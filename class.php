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
    if (!is_dir($base))
      return;
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
  
  protected function exec($cmd) {
    $output = $error = null;
    exec($cmd, $output, $error);
    if ($error)
      $this->error("The following command failed: $cmd", 503);
  }
  
  protected function append($a, $b) {
    $src = fopen($a, 'r');
    $dest = fopen($b, 'ab');
    stream_copy_to_stream($src, $dest);
    fclose($src);
    fclose($dest);
  }
  
  /*
   * Exports the love project to Microsoft Windows
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportWindows($out, $ops) {
    // fuse executable
    $tmp = $ops['tmp'];
    $project = $ops['project'];
    rename("$tmp/love.exe", "$tmp/$project.exe");
    $this->append($ops['src'], "$tmp/$project.exe");

    // NSIS installer configuration
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
      if (is_string($v)) {
        $v = str_replace('$', '$$', $v);
        $v = str_replace('"', '$\\"', $v);
        $v = '"'.$v.'"';
      }
     // ([^$"]|$\"|$$)*"
      $info = preg_replace("/!define $k [^\n]*?\n/", "!define $k $v\n", $info, 1);
    }

    file_put_contents("$tmp/installer.nsi", $info);
    copy("$nsis/filesize.nsi", "$tmp/filesize.nsi");
    
    // license agreement
    copy("$nsis/readme.txt", "$tmp/readme.txt");

    // icon
    copy("$nsis/logo.ico", "$tmp/logo.ico");
    $icon = $ops['icon'];
    if (is_file($icon)) {
      $sizes = [ 16,20,24,30,32,36,40,48,60,64,72,80,96,256 ];
      foreach ($sizes as $k => $v)
        $sizes[$k] = [ $v, $v ];
      require(__DIR__.'/icons.php' );
      $lib = new \PHP_ICO($icon, $sizes);
      $lib->save_ico("$tmp/logo.ico");
    }
    
    // build
    $this->exec("makensis $tmp/installer.nsi");
    rename("$tmp/$project-install.exe", $out);
  }
  
  /*
   * Exports the love project to Linux as an AppImage
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportLinux($out, $ops) {
    // fuse executable
    $tmp = $ops['tmp'];
    $sqbin = ($ops['version'] == '11.4') ? "$tmp/bin" : "$tmp/usr/bin";
    $sqbin = realpath($sqbin);
    if (!is_dir($sqbin)) {
      $this->error('The project binaries were not found', 503);
      return;
    }
    $project = $ops['project'];
    rename("$sqbin/love", "$sqbin/$project");
    $this->append($ops['src'], "$sqbin/$project");
    // permissions
    $dir = new DirectoryIterator($sqbin);
    foreach ($dir as $fileinfo)
      if (!$fileinfo->isDot())
        $this->exec('chmod +x '.$sqbin.'/'.$fileinfo->getFilename());
    $this->exec("chmod +x $tmp/AppRun");
    if (!is_executable("$sqbin/$project")) {
      $this->error('The project binaries cannot be fused', 503);
      return;
    }

    // .desktop file metadata
    $info = file_get_contents("$tmp/love.desktop");
    unlink("$tmp/love.desktop");
    $array = [
      'Exec' => $project,
      'Name' => $ops['title'],
      'Comment' => $ops['description'],
      'Categories' => 'Game;',
      'Icon' => $project,
      'NoDisplay' => 'false',
      'Terminal' => 'false',
    ];
    foreach ($array as $k => $v) {
      if (is_string($v))
        $v = str_replace(PHP_EOL, " ", $v);
      $info = preg_replace("/$k=[^\n]*?\n/", "$k=$v\n", $info, 1);
    }
    file_put_contents("$tmp/$project.desktop", $info);

    // application icon
    rename("$tmp/love.svg", "$tmp/$project.svg");
    $icon = $ops['icon'];
    if (is_file($icon)) {
      unlink("$tmp/$project.svg");
      $data = file_get_contents($icon);
      file_put_contents("$tmp/.DirIcon", $data);
      file_put_contents("$tmp/$project.png", $data);
    }

    // build
    $appimg = realpath($this->bin.'/appimagetool-x86_64.AppImage');
    if (!is_executable($appimg)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    $this->exec("$appimg $tmp $out");
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
    
    $project = $ops['project'];
    $src = new ZipArchive;
    $src->open($out);
    // love file
    //$src->addFromString('love.app/Contents/Resources/'.$project.'.love', $cont);
    $src->addFile($ops['src'], 'love.app/Contents/Resources/'.$project.'.love');
    // information
    $array = [
      'CFBundleName' => $project,
      'CFBundleShortVersionString' => $ops['version'],
      'NSHumanReadableCopyright' => 'Packaged by 2dengine.com',
      //'UTExportedTypeDeclarations' => false,
    ];
    $info = $src->getFromName('love.app/Contents/Info.plist');
    foreach ($array as $k => $v) {
      if (is_string($v))
        $v = htmlentities($v);
      $info = preg_replace("/<key>$k<\/key>[\s]*?<string>[\s\S]*?<\/string>/", "<key>$k</key>\n\t<string>$v</string>", $info, 1);
    }
    $src->addFromString('love.app/Contents/Info.plist', $info);
    // icon
    $icon = $ops['icon'];
    if (is_file($icon)) {
      $data = file_get_contents($icon);
      $src->addFromString('love.app/.Icon\r', $data);
      //$src->addFromString('love.app/Contents/Resources/GameIcon.icns', $data);
      //$src->addFromString('love.app/Contents/Resources/OS X AppIcon.icns', $data);
    }
    // rename the love.app directory
    // thanks to deceze from stackoverflow
    for ($i = 0; $i < $src->numFiles; $i++) { 
      $stat = $src->statIndex($i);
      if (!$stat)
        continue;
      $fn = $stat['name'];
      $src->renameName($fn, str_replace('love.app', "$project.app", $fn));
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
    
    // project file
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
    // icon
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
    
    // binaries
    $bin = $this->bin."/love-$version-$platform.zip";
    if (!is_file($bin)) {
      $this->error('The specified platform and version are unsupported:'.$bin, 400);
      return false;
    }
    $tmp = $dest.'_tmp';
    if (is_dir($tmp))
      $this->rmdir($tmp);
    //mkdir("$tmp/");
    // ZipArchive ruins our symlinks so we have to use "unzip"
    //if ($platform != 'macos')
      $this->exec("unzip $bin -d $tmp/");

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
      'tmp' => $tmp,
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
    
    $this->rmdir($tmp);
    
    if (!is_file($dest) or !filesize($dest)) {
      $this->error('The project could not be exported to the specified location', 503);
      return false;
    }
    return true;
  }
  
  function export($handle, $platform, $version = '11.4', $project = null) {
    $tmp = sys_get_temp_dir();
    //$tmp = __DIR__.'/tmp/';
    //if (!is_dir($tmp))
      //mkdir($tmp);
      
    $src = tempnam($tmp, 'love');
    $file = fopen($src, 'wb');
    $url = 'http://localhost/'.ltrim($handle, '/');
    $ch = curl_init($url);
    //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FILE, $file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($file);
    
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
