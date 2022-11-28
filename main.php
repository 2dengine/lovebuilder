<?php
define('BIN', __DIR__.'/bin');
define('CACHE', __DIR__.'/tmp');
define('MAX_UPLOAD_SIZE', 80000000);

class Builder {
  protected $version;
  protected $project;
  protected $content;
  
  function __construct() {
    if (!is_writable(CACHE))
      throw new ErrorException('Cache directory is not writable', 500);
  }
/*
  protected function temp($prefix) {
    $real = realpath(CACHE);
    if (substr($real, -1) != '/')
        $real .= '/';
    $tmp = tempnam($real, $prefix);
    $name = basename($tmp);
    if (!is_file($real.$name)) {
      @unlink($name);
      throw new ErrorException('Cannot output temp file:'.$real.$name, 500);
    }
    return $tmp;
  }
*/
  protected function exportWindows($out) {
    $ops = $this->ops;
    $bits = $ops['bits'];
    if ($bits != 32 and $bits != 64)
      throw new ErrorException('Invalid platform architecture', 400);

    // copy zipped binaries
    //$out = $this->temp('zip');
    if (!copy($this->zip, $out))
      throw new ErrorException('Cannot output love:'.$out, 500);
    $src = new ZipArchive;
    $src->open($out);

    // fuse executable
    $proj = $ops['project'];
    $old = $src->getFromName('love.exe');
    $src->deleteName('love.exe');
    $src->addFromString($proj.'.exe', $old.$this->content);

    if (!$ops['https'])
      $src->deleteName('https.dll');

    // re-compress
    $src->close();
    //unlink($fuse);
    return $out;
  }

  protected function rrmdir($dir) {
    // thanks to the PHP manual page
    if (is_dir($dir)) {
      $objects = scandir($dir);
      foreach ($objects as $object) { 
        if ($object != "." && $object != "..") { 
          if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object))
            $this->rrmdir($dir. DIRECTORY_SEPARATOR.$object);
          else
            unlink($dir.DIRECTORY_SEPARATOR.$object); 
        } 
      }
      rmdir($dir); 
    } 
  }
  
  protected function exportLinux($out) {
    $ops = $this->ops;
    // extract zipped app image
    $squash = $out.'.squash/';
    $this->rrmdir($squash);
    mkdir($squash);
/*
    $src = new ZipArchive;
    $src->open($this->zip, ZipArchive::RDONLY);
    $src->extractTo($squash);
    $src->close();
    */
    $bins = $this->zip;
    exec("unzip $bins -d $squash");

    if (!$ops['https'])
      unlink($squash.'/https.so');

    // fuse
    $ver = $ops['ver'];
    $proj = $ops['project'];
    $cont = $this->content;
    $sqbin = ($ver == '11.4') ? $squash.'/bin' : $squash.'/usr/bin';
    $sqbin = realpath($sqbin);
    if (!is_dir($sqbin))
      throw new ErrorException('Binaries extraction failed', 500);
    $old = file_get_contents($sqbin.'/love');
    unlink($sqbin.'/love');
    file_put_contents($sqbin.'/'.$proj, $old.$cont);
    // make executable
    $dir = new DirectoryIterator($sqbin);
    foreach ($dir as $fileinfo)
      if (!$fileinfo->isDot())
        exec('chmod +x '.$sqbin.'/'.$fileinfo->getFilename());
    exec('chmod +x '.$squash.'/AppRun');
    
    if (!is_executable($sqbin.'/'.$proj))
      throw new ErrorException('Could not make executable', 500);
    
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
        throw new ErrorException('Linux application icon must be in .PNG or .SVG format', 400);
      }
    }
*/
    $appimg = realpath(BIN.'/appimagetool-x86_64.AppImage');
    if (!is_executable($appimg))
      throw new ErrorException('Could not execute AppImageTool', 500);

    // build
    //$out = $this->temp('img');
    exec($appimg.' '.$squash.' '.$out);
    //exec('rm '.$squash.' -r');
    $this->rrmdir($squash);

    return $out;
  }
  
  protected function exportMacOS($out) {
    $ops = $this->ops;
    //$out = $this->temp('app');
    copy($this->zip, $out);
    $version = $ops['ver'];
    $proj = $ops['project'];
    $cont = $this->content;
    
    $src = new ZipArchive;
    $src->open($out);
    // love file
    $src->addFromString('love.app/Contents/Resources/'.$proj.'.love', $cont);

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
        throw new ErrorException('MacOS application icon must be in .ICNS or .PNG format', 400);
      }
    }
*/

    // include luasec
    if (!$ops['https'])
      $src->deleteName('love.app/Contents/Frameworks/https.so');

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
    return $out;
  }
  
  protected function exportWeb($out) {
    $ops = $this->ops;

    if ($ops['https'])
      throw new ErrorException('HTTPS is not supported by love.js', 400);

    //$out = $this->temp('web');
    copy($this->zip, $out);
    $proj = $ops['project'];
    $cont = $this->content;
    $src = new ZipArchive;
    $src->open($out);
    $src->addFromString($proj.'.love', $cont);
    $player = $src->getFromName('player.js');
    $player = str_replace('nogame.love', $proj.'.love', $player);
    $src->addFromString('player.js', $player);
    $src->close();
    return $out;
  }

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
    return min(tobytes('upload_max_filesize'), tobytes('post_max_size'));
  }

  function upload($data) {
    if (strlen($data) == 0)
      throw new ErrorException('Love file is required', 400);
    if (strlen($data) > MAX_UPLOAD_SIZE)
      throw new ErrorException('Love file is too large', 400);

    $love = CACHE.'/'.crc32($data);
    if (!is_file($love))
      file_put_contents($love, $data);
    $handle = pathinfo($love, PATHINFO_BASENAME);
    return $handle;
  }
  
  function export($handle, $ops) {
    $handle = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $handle);
    $love = realpath(CACHE.'/'.$handle);
    if (!$love)
      throw new ErrorException('Invalid handle', 401);

    $zip = new ZipArchive;
    if ($zip->open($love, ZipArchive::RDONLY) !== true)
      throw new ErrorException('Love file is not a zip archive:'.$love, 400);
    if (!$zip->locateName('main.lua'))
      throw new ErrorException('Cannot locate main.lua:'.$love, 400);
    $zip->close();

    if (!$ops['project'])
      throw new ErrorException('Invalid project name', 400);
    
    $ver = $ops['ver'];
    if ($ver != '11.3' and $ver != '11.4')
      throw new ErrorException('Unsupported Love2D version:'.$ver, 400);

    $os = $ops['platform'];
    if (!isset($ops['bits']))
      $ops['bits'] = ($os == 'win32') ? 32 : 64;
    $dest = $love.'.'.crc32(serialize($ops));

    if (!is_file($dest)) {
      $this->content = file_get_contents($love);
      //@unlink($love);
      $this->zip = BIN.'/love-'.$ver.'-'.$os.'.zip';
      $this->ops = $ops;

      if ($os == 'win32' or $os == 'win64') {
        $this->exportWindows($dest);
      } elseif ($os == 'linux') {
        $this->exportLinux($dest);
      } elseif ($os == 'macos') {
        $this->exportMacOS($dest);
      } elseif ($os == 'web') {
        $this->exportWeb($dest);
      } else {
        throw new ErrorException('Unsupported target platform', 400);
      }

      if (!$dest or !is_file($dest))
        throw new ErrorException('Could not build project', 500);
    }
    $handle = pathinfo($dest, PATHINFO_BASENAME);
    //return $this->fetch($handle);
    return $handle;
  }

  function fetch($handle, $filename) {
    $handle = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $handle);
    $src = realpath(CACHE.'/'.$handle);
    if (!is_file($src))
      throw new ErrorException('The download link has expired', 401);

    $filename = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $filename);

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.basename($filename).'"');
    header('Content-Length: '.filesize($src));
    header('Pragma: public');

    return file_get_contents($src);
  }
  
  function close($since = 15*60) {
    // cleanup anything older than 15 minutes
    $now = time();
    $dir = new DirectoryIterator(CACHE);
    foreach ($dir as $info) {
      if (!$info->isDot())
        if ($now - $info->getMTime() > $since)
          @unlink($info->getFilename());
    }
  }
}
