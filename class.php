<?php
// Love2D binaries directory
define('BIN', __DIR__.'/bin');
// Temporary upload cache folder
define('CACHE', __DIR__.'/tmp');
// Maximum upload size if smaller than what is allowed by the server
define('MAX_UPLOAD_SIZE', 250000000);

class Builder {
  protected $except;
  protected $bin;
  protected $src;
  
  function __construct($except = true) {
    $this->except = $except;
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
      $path = $base.DIRECTORY_SEPARATOR.$info->getFilename();
      if ($info->isDir()) {
        $this->cleanup($path, $since);
        rmdir($path);
      } elseif ($now - $info->getMTime() > $since) {
        unlink($path);
      }
    }
  }

  /*
   * Prepares the object for I/O operations
   * @return True if successful
   */
  function open() {
    // check if the cache folder is writable
    if (!is_writable(CACHE)) {
      $this->error('Cache directory is not writable', 500);
      return false;
    }
    return true;
  }

  /*
   * Cleans up the cache
   */
  function close() {
    $this->cleanup(CACHE, 15*60);
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
    return min(tobytes('upload_max_filesize'), tobytes('post_max_size'), MAX_UPLOAD_SIZE);
  }

  /*
   * Exports the love project to Microsoft Windows
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportWindows($out, $ops) {
    $bits = $ops['bits'];
    if ($bits != 32 and $bits != 64) {
      $this->error('Invalid platform architecture', 400);
      return;
    }

    // copy zipped binaries
    //$out = $this->temp('zip');
    if (!copy($this->bin, $out)) {
      $this->error('Cannot output love:'.$out, 500);
      return;
    }
    $src = new ZipArchive;
    $src->open($out);

    // fuse executable
    $old = $src->getFromName('love.exe');
    $cont = file_get_contents($this->src);

    $temp = tempnam(CACHE, 'exe');
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
  
  /*
   * Exports the love project to Linux as an AppImage
   * @param $out Destination path
   * @param $ops Options array
   */
  protected function exportLinux($out, $ops) {
    // extract zipped app image
    $squash = $out.'.squash/';
    $this->removeDirectory($squash);
    mkdir($squash);
/*
    $src = new ZipArchive;
    $src->open($this->bin, ZipArchive::RDONLY);
    $src->extractTo($squash);
    $src->close();
    */
    $bin = $this->bin;
    exec("unzip $bin -d $squash");

    // fuse
    $ver = $ops['version'];
    $proj = $ops['project'];
    $cont = file_get_contents($this->src);
    $sqbin = ($ver == '11.4') ? $squash.'/bin' : $squash.'/usr/bin';
    $sqbin = realpath($sqbin);
    if (!is_dir($sqbin)) {
      $this->error('Binaries extraction failed', 500);
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
      $this->error('Could not make executable', 500);
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
    $appimg = realpath(BIN.'/appimagetool-x86_64.AppImage');
    if (!is_executable($appimg)) {
      $this->error('Could not execute AppImageTool', 500);
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
    copy($this->bin, $out);

    $version = $ops['version'];
    $proj = $ops['project'];
    //$cont = file_get_contents($this->src);
    
    $src = new ZipArchive;
    $src->open($out);
    // love file
    //$src->addFromString('love.app/Contents/Resources/'.$proj.'.love', $cont);
    $src->addFile($this->src, 'love.app/Contents/Resources/'.$proj.'.love');
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
    // thanks to https://stackoverflow.com/users/476/deceze
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
    copy($this->bin, $out);
    $proj = $ops['project'];
    //$cont = file_get_contents($this->src);
    $src = new ZipArchive;
    $src->open($out);
    //$src->addFromString($proj.'.love', $cont);
    $src->AddFile($this->src, $proj.'.love');
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
    if (strlen($data) == 0) {
      $this->error('Love file is required', 400);
      return;
    }
    if (strlen($data) > $this->getMaxUploadSize()) {
      $this->error('Love file is too large', 400);
      return;
    }

    $love = CACHE.'/'.crc32($data);
    if (is_file($love))
      touch($love);
    else
      file_put_contents($love, $data);
    $handle = pathinfo($love, PATHINFO_BASENAME);
    return $handle;
  }
  
  /*
   * Exports a previously uploaded file based on a handle
   * @param $handle Uploaded file handle
   * @param $ops Options array
   * @return Exported file handle
   */
  function export($handle, $ops) {
    $handle = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $handle);
    $love = realpath(CACHE.'/'.$handle);
    if (!$handle or !$love) {
      $this->error('Missing or invalid handle', 401);
      return;
    }

    $zip = new ZipArchive;
    if ($zip->open($love, ZipArchive::RDONLY) !== true) {
      $this->error('File is not a zip archive', 400);
      return;
    }
    if ($zip->locateName('main.lua') === false) {
      $this->error('Cannot locate main.lua:'.$love, 400);
      return;
    }
    $zip->close();

    if (!$ops['project']) {
      $this->error('Invalid project name', 400);
      return;
    }
    
    $ver = $ops['version'];
    $os = $ops['platform'];
    $bin = BIN.'/love-'.$ver.'-'.$os.'.zip';

    if (!is_file($bin)) {
      $this->error('Unsupported platform or version', 400);
      return;
    }
    if (!isset($ops['bits']))
      $ops['bits'] = ($os == 'win32') ? 32 : 64;

    $dest = $love.'.'.crc32(serialize($ops));

    if (is_file($dest)) {
      touch($dest);
    } else {
      $this->src = $love; //file_get_contents($love);
      $this->bin = $bin;

      if ($os == 'win32' or $os == 'win64') {
        $this->exportWindows($dest, $ops);
      } elseif ($os == 'linux') {
        $this->exportLinux($dest, $ops);
      } elseif ($os == 'macos') {
        $this->exportMacOS($dest, $ops);
      } elseif ($os == 'web') {
        $this->exportWeb($dest, $ops);
      } else {
        $this->error('Unsupported target platform', 400);
        return;
      }

      if (!is_file($dest)) {
        $this->error('Could not build project', 500);
        return;
      }
    }
    $handle = pathinfo($dest, PATHINFO_BASENAME);
    //return $this->fetch($handle);
    return $handle;
  }

  /*
   * Downloads a previously exported project based on a handle
   * @param $handle Exported file handle
   * @param $filename Desired filename
   * @return Raw file contents
   */
  function fetch($handle, $filename = 'download.zip') {
    $handle = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $handle);
    $src = realpath(CACHE.'/'.$handle);
    if (!$handle or !is_file($src)) {
      $this->error('The download link has expired', 401);
      return;
    }

    $filename = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $filename);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($filename).'"');
    header('Content-Length: '.filesize($src));
    header('Pragma: public');

    return file_get_contents($src);
  }
}