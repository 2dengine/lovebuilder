<?php
namespace dengine;

use ZipArchive;
use ErrorException;
use DirectoryIterator;

class Build {
  protected $except;
  protected $bin;

  function __construct($except = true) {
    $this->except = $except;
    // Love2D binaries directory
    $this->bin = realpath(__DIR__.'/bin/');
  }

  /*
   * Raises an error by throwing an exception
   * @param $message Error message
   * @param $code Error code
   */
  protected function error($message, $code) {
    if ($this->except)
      throw new ErrorException($message, $code);
  }

  /*
   * Recursively removes a directory including its contents
   * @param $base Directory path
   */
  protected function rmdir($base) {
    $dir = new DirectoryIterator($base);
    foreach ($dir as $info) {
      if ($info->isDot())
        continue;
      $path = $info->getPathname();
      if ($info->isDir()) {
        $this->rmdir($path);
        @rmdir($path);
      } else {
        @unlink($path);
      }
    }
    @rmdir($base);
  }
  
  /*
   * Exports the love project to Microsoft Windows
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportWindows($out, $ops) {
    $squash = $out.'_tmp';
    if (is_dir($squash))
      $this->rmdir($squash);
    mkdir($squash);
    $bin = $ops['bin'];
    exec("unzip $bin -d $squash");
    
    // fuse executable
    $project = $ops['project'];
    rename("$squash/love.exe", "$squash/$project.exe");
    $src = fopen($ops['src'], 'r');
    $dest = fopen("$squash/$project.exe", 'ab');
    stream_copy_to_stream($src, $dest);
    fclose($src);
    fclose($dest);

    // windows installer configuration
    $bits = $ops['bits'];
    $nsis = __DIR__.'/nsis';
    $info = file_get_contents("$nsis/installer.nsi");
    $array = [
      'IDENTITY' => $project,
      'TITLE' => $ops['title'],
      'DESCRIPTION' => $ops['description'],
      'PUBLISHER' => $ops['publisher'],
      'URL' => $ops['url'],
      'MAJOR' => $ops['major'],
      'MINOR' => $ops['minor'],
      'BUILD' => $ops['build'],
      'PROGRAMS' => '$PROGRAMFILES'.$bits,
    ];
    foreach ($array as $k => $v) {
      if (is_string($v))
        $v = '"'.$v.'"';
      $info = preg_replace("/!define $k [^\n]*?\n/", "!define $k $v\n", $info, 1);
    }

    file_put_contents("$squash/installer.nsi", $info);
    //copy("$nsis/installer.nsi", "$squash/installer.nsi");
    copy("$nsis/filesize.nsi", "$squash/filesize.nsi");
    
    // license agreement
    copy("$nsis/readme.txt", "$squash/readme.txt");
    // icon
    copy("$nsis/logo.ico", "$squash/logo.ico");
    if ($ops['icon']) {
      $sizes = [ 16,20,24,30,32,36,40,48,60,64,72,80,96,256 ];
      foreach ($sizes as $k => $v)
        $sizes[$k] = [ $v, $v ];
      require(__DIR__.'/icons.php' );
      $lib = new \PHP_ICO($ops['icon'], $sizes);
      $lib->save_ico("$squash/logo.ico");
    }
    
    // build
    exec("makensis $squash/installer.nsi");
    copy("$squash/$project-install.exe", $out);
  }
  
  /*
   * Exports the love project to Linux as an AppImage
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportLinux($out, $ops) {
    // extract zipped app image
    $squash = $out.'_squash';
    if (is_dir($squash))
      $this->rmdir($squash);
    mkdir($squash);
/*
    // the following technique ruins our symlinks
    $zip = new ZipArchive;
    $zip->open($ops['bin'], ZipArchive::RDONLY);
    $zip->extractTo($squash);    
    $zip->close();
*/
    $bin = $ops['bin'];
    exec("unzip $bin -d $squash");

    // fuse executable
    $sqbin = ($ops['version'] == '11.4') ? "$squash/bin" : "$squash/usr/bin";
    $sqbin = realpath($sqbin);
    if (!is_dir($sqbin)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    $project = $ops['project'];
    $old = file_get_contents($sqbin.'/love');
    unlink($sqbin.'/love');
    $src = fopen($ops['src'], 'r');
    $dest = fopen($sqbin.'/'.$project, 'wb');
    fwrite($dest, $old);
    stream_copy_to_stream($src, $dest);
    fclose($src);
    fclose($dest);

    // make executable
    $dir = new DirectoryIterator($sqbin);
    foreach ($dir as $fileinfo)
      if (!$fileinfo->isDot())
        exec('chmod +x '.$sqbin.'/'.$fileinfo->getFilename());
    exec("chmod +x $squash/AppRun");
    if (!is_executable("$sqbin/$project")) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }

    // information
    $info = file_get_contents("$squash/love.desktop");
    unlink("$squash/love.desktop");
    $array = [
      'Exec' => $project,
      'Name' => $ops['title'],
      'Comment' => $ops['description'],
      'Categories' => 'Game;',
      'Icon' => $project,
      'NoDisplay' => 'false',
      'Terminal' => 'false',
    ];
    foreach ($array as $k => $v)
      $info = preg_replace("/$k=[^\n]*?\n/", "$k=$v\n", $info, 1);
    file_put_contents("$squash/$project.desktop", $info);

    // icon
    rename("$squash/love.svg", "$squash/$project.svg");
    $icon = $ops['icon'];
    if ($icon) {
      unlink("$squash/logo.svg");
      $data = file_get_contents($icon);
      file_put_contents("$squash/.DirIcon", $data);
      file_put_contents("$squash/$project.png", $data);
    }

    // build
    $appimg = realpath($this->bin.'/appimagetool-x86_64.AppImage');
    if (!is_executable($appimg)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    exec("$appimg $squash $out");

    //exec("rm $squash -r");
    //$this->rmdir($squash);
  }
  
  /*
   * Exports the love project to MacOS
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportMacOS($out, $ops) {
    // copy zipped binaries
    if (!copy($ops['bin'], $out)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }

    $version = $ops['version'];
    $proj = $ops['project'];
    //$cont = file_get_contents($ops['src']);
    
    $src = new ZipArchive;
    $src->open($out);
    // love file
    //$src->addFromString('love.app/Contents/Resources/'.$proj.'.love', $cont);
    $src->addFile($ops['src'], 'love.app/Contents/Resources/'.$proj.'.love');
    // information
    $info = $src->getFromName('love.app/Contents/Info.plist');
    $info = preg_replace('/<key>CFBundleName<\/key>[\s]*?<string>[\s\S]*?<\/string>/', "<key>CFBundleName</key>\n\t<string>$proj</string>", $info, 1);
    $info = preg_replace('/<key>CFBundleShortVersionString<\/key>[\s]*?<string>[\s\S]*?<\/string>/', "<key>CFBundleShortVersionString</key>\n\t<string>$version</string>", $info, 1);
    //$info = preg_replace('/<key>UTExportedTypeDeclarations<\/key>[\s]*?<array>[\s\S]*?<\/array>/', "", $info, 1);
    //$src->deleteName('love.app/Contents/Info.plist');
    $src->addFromString('love.app/Contents/Info.plist', $info);
/*
    // icon
    if ($icon) {
      //$iconext = strtolower($icon, PATHINFO_EXTENSION);
      $mime = mime_content_type($icon);
      $res = file_get_contents($icon);
      if ($mime == 'image/x-icns') {
        $src->addFromString('love.app/Contents/Resources/GameIcon.icns', $res);
        $src->addFromString('love.app/Contents/Resources/OS X AppIcon.icns', $res);
      } elseif ($mime == 'image/png') {
        $src->addFromString('love.app/.Icon\r', $res);
      } else {
        $this->error('MacOS application icon must be in .ICNS or .PNG format', 400);
      }
    }
*/
    // rename the love.app directory
    // thanks to deceze from stackoverflow
    for ($i = 0; $i < $src->numFiles; $i++) { 
      $stat = $src->statIndex($i);
      if (!$stat)
        continue;
      $fn = $stat['name'];
      $src->renameName($fn, str_replace('love.app', $proj.'.app', $fn));
    }

    $src->close();
  }

  /*
   * Exports a previously uploaded file based on a handle
   * @param $src Path to the .love file to be read
   * @param $dest Path to the resulting binary file to be written
   * @param $platform Target platform
   * @param $version Love2D version
   * @param $project Project name, if different from the source filename
   * @return True if successful
   */
  protected function exportFile($src, $dest, $platform, $version = '11.4', $project = null) {
    if (!$project)
      $project = pathinfo($src, PATHINFO_FILENAME);
    $project = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $project);
    $version = preg_replace('/[^0-9\.]/', '', $version);
    if (!$src or !is_file($src)) {
      $this->error('The project filename is invalid or does not exist', 404);
      return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($src, ZipArchive::RDONLY) !== true) {
      $this->error('The project file does not appear to be a valid archive', 400);
      return false;
    }
    if ($zip->locateName('main.lua') === false) {
      $this->error('The project file does not contain main.lua or may be packaged incorrectly', 400);
      return false;
    }

    // metadata from conf.lua
    $conf = $zip->getFromName('conf.lua');
    $title = $project;
    if ($conf) {
      preg_match('/.title\s*=\s*"([^"\n]+)"/', $conf, $matches);
      if (!isset($matches[1]))
        preg_match("/.title\s*=\s*'([^'\n]+)'/", $conf, $matches);
      if (isset($matches[1]))
        $title = $matches[1];
    }

    $icon = false;
    $png = $zip->getFromName('logo.png');
    if ($png) {
      $tmp = sys_get_temp_dir();
      $icon = tempnam($tmp, 'icon');
      file_put_contents($icon, $png);
      $size = getimagesize($icon);
      if ($size['mime'] != 'image/png') {
        $this->error('The application icon must be in .PNG format', 400);
        return false;
      }
      if ($size[0] != 512 or $size[1] != 512) {
        $this->error('The application icon must be 512x512 pixels', 400);
        return false;
      }
    }
    
    $zip->close();

    $bin = $this->bin."/love-$version-$platform.zip";
    if (!is_file($bin)) {
      $this->error('The specified platform and version are unsupported:'.$bin, 400);
      return false;
    }

    $ops = array(
      'project' => $project,
      'title' => $title,
      'description' => 'Packaged by 2dengine.com',
      'publisher' => '2dengine',
      'url' => 'https://2dengine.com',
      'major' => 1,
      'minor' => 0,
      'build' => 0,
      'platform' => $platform,
      'version' => $version,
      'src' => $src,
      'bin' => $bin,
      'bits' => ($platform == 'win32') ? 32 : 64,
      'icon' => $icon,
    );

    if ($platform == 'win32' or $platform == 'win64')
      $this->exportWindows($dest, $ops);
    elseif ($platform == 'linux')
      $this->exportLinux($dest, $ops);
    elseif ($platform == 'macos')
      $this->exportMacOS($dest, $ops);
    elseif ($platform == 'web')
      $this->exportWeb($dest, $ops);
    else {
      $this->error('The specified platform is unsupported', 400);
      return;
    }
    if (!is_file($dest) or !filesize($dest)) {
      $this->error('The project could not be exported to the specified location', 503);
      return false;
    }
    return true;
  }
  
  function export($handle, $platform, $version = '11.4', $project = null) {
    $tmp = sys_get_temp_dir();
    if (!is_dir($tmp))
      mkdir($tmp);
    $src = tempnam($tmp, 'love');
    $file = fopen($src, 'wb');

    $url = 'http://localhost/'.ltrim($handle, '/');
    $ch = curl_init($url);
    //curl_setopt($ch, CURLOPT_HEADER, true);
    //curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FILE, $file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
/*
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);    
    if (curl_errno($ch) or $code != 200) {
      $this->error(curl_error($ch), 400);
      return false;
    }
    */
    //$header = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    //$body = substr($data, $header);
    curl_close($ch);
    
    //$data = file_get_contents($_SERVER['SERVER_NAME'].$handle);
    //file_put_contents($src, $body);
    $dest = tempnam($tmp, 'bin');
    if (!$this->exportFile($src, $dest, $platform, $version, $project))
      return;
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($dest).'"');
    header('Content-Length: '.filesize($dest));
    header('Pragma: public');
    http_response_code(200);
    
    readfile($dest);
    exit;
  }
}
