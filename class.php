<?php
namespace dengine;

use ZipArchive;
use ErrorException;
use DirectoryIterator;

class Build {
  protected $except;
  protected $cache;
  protected $bin;
  protected $maxsize;

  function __construct($except = true) {
    $this->except = $except;
    // Temporary uploads folder
    $this->cache = __DIR__.'/tmp/';
    // Love2D binaries directory
    $this->bin = __DIR__.'/bin/';
    // Maximum upload size if smaller than allowed by the server
    $this->maxsize = 250000000;

    if (!is_writable($this->cache))
      $this->error('The cache directory is missing or may be write-protected', 503);
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
   * Removes unused files that are older than the provided timestamp
   * @param $base Directory path
   * @param $since Timestamp
   */
  protected function cleanup($base, $since) {
    // cleanup anything older than X-minutes
    $now = time();
    $dir = new DirectoryIterator($base);
    foreach ($dir as $info) {
      if ($info->isDot())
        continue;
      $path = $base.'/'.$info->getFilename();
      if ($info->isDir()) {
        $this->cleanup($path, $since);
        rmdir($path);
      } elseif ($now - $info->getMTime() > $since) {
        unlink($path);
      }
    }
  }

  /*
   * Finds the maximum file upload size
   * @return Size in bytes
   */
  function getMaxUploadSize() {
    function tobytes($key) {
      $convert = array('g' => 1024*1024*1024, 'm' => 1024*1024, 'k' => 1024);
      $s = ini_get($key);
      $s = trim($s);
      $q = strtolower($s[strlen($s)-1]);
      $v = (int)$s;
      if (isset($convert[$q]))
        $v *= $convert[$q];
      return $v;
    }
    return min(tobytes('upload_max_filesize'), tobytes('post_max_size'), $this->maxsize);
  }

  /*
   * Exports the love project to Microsoft Windows
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportWindows($out, $ops) {
    // copy zipped binaries
    if (!copy($ops['bin'], $out)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    $src = new ZipArchive;
    $src->open($out);

    // fuse executable
    $old = $src->getFromName('love.exe');
    $cont = file_get_contents($ops['src']);

    $temp = tempnam($this->cache, 'exe');
    $file = fopen($temp, 'w');
    fwrite($file, $old);
    fwrite($file, $cont);
    fflush($file);
    fclose($file);

    $proj = $ops['project'];
    $src->deleteName('love.exe');
    //$src->addFromString($proj.'.exe', $old.$cont);
    $src->addFile($temp, $proj.'.exe');

    // re-compress
    $src->close();
    //unlink($fuse);
    unlink($temp);
  }
  
  private function removeDirectory($path) {
    $dir = new DirectoryIterator($path);
    foreach ($dir as $info) {
      if ($info->isDot())
        continue;
      $full = $info->getPathname();
      if ($info->isDir())
        if ($this->removeDirectory($full))
          @rmdir($full);
      if ($info->isFile())
        @unlink($full);
    }
  }
  
  /*
   * Exports the love project to Linux as an AppImage
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportLinux($out, $ops) {
    // extract zipped app image
    $squash = $out.'.squash/';
    if (is_dir($squash))
      $this->removeDirectory($squash);
    mkdir($squash);
/*
    $src = new ZipArchive;
    $src->open($ops['bin'], ZipArchive::RDONLY);
    $src->extractTo($squash);
    $src->close();
    */
    $bin = $ops['bin'];
    exec("unzip $bin -d $squash");

    // fuse
    $ver = $ops['version'];
    $proj = $ops['project'];
    $cont = file_get_contents($ops['src']);
    $sqbin = ($ver == '11.4') ? $squash.'/bin' : $squash.'/usr/bin';
    $sqbin = realpath($sqbin);
    if (!is_dir($sqbin)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    $old = file_get_contents($sqbin.'/love');
    unlink($sqbin.'/love');
    file_put_contents($sqbin.'/'.$proj, $old.$cont);
    // make executable
    $dir = new DirectoryIterator($sqbin);
    foreach ($dir as $fileinfo)
      if (!$fileinfo->isDot())
        exec('chmod +x '.$sqbin.'/'.$fileinfo->getFilename());
    exec('chmod +x '.$squash.'/AppRun');
    
    if (!is_executable($sqbin.'/'.$proj)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    
    // information
    $info = file_get_contents($squash.'/love.desktop');
    $info = preg_replace("/Name=[^\n]*?\n/", "Name=$proj\n", $info, 1);
    $info = preg_replace("/Exec=[^\n]*?\n/", "Exec=$proj %f\n", $info, 1);
    file_put_contents($squash.'/love.desktop', $info);
/*
    // icon
    if ($icon) {
      $mime = mime_content_type($icon);
      $res = file_get_contents($icon);
      if ($mime == 'image/svg+xml' or $mime == 'image/svg') {
        unlink($squash.'/love.svg');
        file_put_contents($squash.'/love.svg', $res);
      } elseif ($mime == 'image/png') {
        unlink($squash.'/love.svg');
        file_put_contents($squash.'/love.png', $res);
      } else {
        $this->error('Linux application icon must be in .PNG or .SVG format', 400);
      }
    }
*/
    $appimg = realpath($this->bin.'appimagetool-x86_64.AppImage');
    if (!is_executable($appimg)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }

    // build
    //$out = $this->temp('img');
    exec($appimg.' '.$squash.' '.$out);
    //exec('rm '.$squash.' -r');
    $this->rrmdir($squash);
  }
  
  /*
   * Exports the love project to MacOS
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportMacOS($out, $ops) {
    //$out = $this->temp('app');
    copy($ops['bin'], $out);

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
   * Exports the love project to the web using Love.js
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportWeb($out, $ops) {
    //$out = $this->temp('web');
    copy($ops['bin'], $out);
    $proj = $ops['project'];
    //$cont = file_get_contents($ops['src']);
    $src = new ZipArchive;
    $src->open($out);
    //$src->addFromString($proj.'.love', $cont);
    $src->AddFile($ops['src'], $proj.'.love');
    $player = $src->getFromName('player.js');
    $player = str_replace('nogame.love', $proj.'.love', $player);
    $src->addFromString('player.js', $player);
    $src->close();
  }

  /*
   * Uploads a zipped Love2D project file and assigns it a handle
   * @param $data Raw data
   * @return Uploaded file handle
   */
  function upload($data) {
    if (strlen($data) == 0 or strlen($data) > $this->getMaxUploadSize()) {
      $this->error('The uploaded file is invalid or exceeds the maximum allowed size', 400);
      return;
    }

    $handle = sprintf('%u', crc32($data));
    $file = $this->cache.$handle;
    if (is_file($file))
      touch($file);
    else
      file_put_contents($file, $data);
    return $handle;
  }
  
  /*
   * Exports a previously uploaded file based on a handle
   * @param $handle Uploaded file handle
   * @param $project Project name
   * @param $platform Target platform
   * @param $version Love2D version
   * @return Exported file handle
   */
  function export($handle, $project, $platform, $version = '11.4') {
    $handle = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $handle);
    $project = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $project);
    $version = preg_replace('/[^0-9\.]/', '', $version);

    $love = $this->cache.$handle;
    if (!$handle or !$love) {
      $this->error('The upload handle is invalid or may have expired', 404);
      return;
    }

    if (!$project) {
      $this->error('The project title is invalid', 400);
      return;
    }
    $zip = new ZipArchive;
    if ($zip->open($love, ZipArchive::RDONLY) !== true) {
      $this->error('The project file does not appear to be a valid archive', 400);
      return;
    }
    if ($zip->locateName('main.lua') === false) {
      $this->error('The project file does not contain main.lua or may be packaged incorrectly', 400);
      return;
    }
    $zip->close();

    $bin = $this->bin."love-$version-$platform.zip";
    if (!is_file($bin)) {
      $this->error('The specified platform and version are unsupported', 400);
      return;
    }
    
    $file = $handle.'-'.sprintf('%u', crc32($bin));
    $dest = $this->cache.$file;

    if (is_file($dest)) {
      touch($dest);
    } else {
      $ops = array(
        'project' => $project,
        'platform' => $platform,
        'version' => $version,
        'src' => $love,
        'bin' => $bin,
        'bits'=> ($platform == 'win32') ? 32 : 64,
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
      if (!is_file($dest)) {
        $this->error('The project could not be exported', 503);
        return;
      }
    }
    
    $this->cleanup($this->cache, 15*60);
    
    return $file;
  }

  /*
   * Downloads a previously exported project based on a handle
   * @param $handle Exported file handle
   * @param $filename Desired filename
   * @return Raw file contents
   */
  function fetch($handle, $filename = 'download.zip') {
    $handle = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $handle);

    $src = $this->cache.$handle;
    if (!$handle or !is_file($src)) {
      $this->error('The download handle is invalid or may have expired', 404);
      return;
    }

    $filename = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $filename);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($filename).'"');
    header('Content-Length: '.filesize($src));
    header('Pragma: public');
    http_response_code(200);

    echo file_get_contents($src);
  }
}