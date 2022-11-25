<?php
define('BIN', __DIR__.'/bin');
define('CACHE', __DIR__.'/tmp');

class Builder {
  protected $version;
  protected $project;
  protected $content;
  
  function __construct() {
    if (!is_writable(CACHE))
      $this->error('Cache directory is not writable');
  }

  protected function error($msg) {
    throw new ErrorException($msg);
  }

  protected function temp($prefix) {
    $real = realpath(CACHE);
    if (substr($real, -1) != '/')
        $real .= '/';
    $tmp = tempnam($real, $prefix);
    $name = basename($tmp);
    if (!is_file($real.$name)) {
      @unlink($name);
      $this->error('Cannot output temp file:'.$real.$name);
    }
    return $tmp;
  }
  
  protected function exportWindows($ops) {
    $bits = $ops['bits'];
    if ($bits != 32 and $bits != 64)
      $this->error('Invalid platform architecture');

    // copy zipped binaries
    $out = $this->temp('zip');
    if (!copy($this->zip, $out))
      $this->error('Cannot output love:'.$out);
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
  
  protected function exportLinux($ops) {
    // extract zipped app image
    $squash = CACHE.'/'.uniqid(rand(), true);
    mkdir($squash);

    $src = new ZipArchive;
    $src->open($this->zip, ZipArchive::RDONLY);
    $src->extractTo($squash);
    $src->close();
    //exec("unzip $bins -d $squash");

    // fuse
    $ver = $ops['ver'];
    $proj = $ops['project'];
    $cont = $this->content;
    $sqbin = ($ver == '11.4') ? $squash.'/bin' : $squash.'/usr/bin';
    if (!file_exists($sqbin))
      $this->error('Binaries extraction failed:'.$sqbin);
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
      $this->error('Could not make executable');
    
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
        $this->error('Linux application icon must be in .PNG or .SVG format');
      }
    }
*/
    if (!$ops['https'])
      $src->deleteName('https.so');
    $appimg = realpath(BIN.'/appimagetool-x86_64.AppImage');
    if (!is_executable($appimg))
      $this->error('Could not execute AppImageTool');

    // build
    $out = $this->temp('img');
    exec($appimg.' '.$squash.' '.$out);
    exec('rm '.$squash.' -r');

    return $out;
  }
  
  protected function exportMacOS($ops) {
    $out = $this->temp('app');
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
        $this->error('MacOS application icon must be in .ICNS or .PNG format');
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
  
  protected function exportWeb($ops) {
    if ($ops['https'])
      $this->error('HTTPS is not supported by love.js');

    $out = $this->temp(CACHE, 'web');
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
  
  function export($love, $platform, $ops) {
    if ($ops['project'] === null)
      $ops['project'] = pathinfo($love, PATHINFO_FILENAME);
    if ($ops['ver'] === null)
      $ops['ver'] = '11.4';
    
    $zip = new ZipArchive;
    if ($zip->open($love, ZipArchive::RDONLY) !== true)
      $this->error('Love file is not a zip archive:'.$love);
    if (!$zip->locateName('main.lua'))
      $this->error('Cannot locate main.lua:'.$love);
    $zip->close();
    
    $ver = $ops['ver'];
    if ($ver != '11.3' and $ver != '11.4')
      $this->error('Unsupported Love2D version:'.$ver);

    $os = $platform;
    $this->content = file_get_contents($love);
    $this->zip = BIN.'/love-'.$ver.'-'.$os.'.zip';

    if ($ops['bits'] === null)
      $ops['bits'] = ($os == 'win32') ? 32 : 64;

    $out = null;
    if ($os == 'win32' or $os == 'win64') {
      $out = $this->exportWindows($ops);
    } elseif ($os == 'linux') {
      $out = $this->exportLinux($ops);
    } elseif ($os == 'macos') {
      $out = $this->exportMacOS($ops);
    } elseif ($os == 'web') {
      $out = $this->exportWeb($ops);
    } else {
      $this->error('Unsupported target platform');
    }
    return $out;
  }
  
  function cleanup($since = 15*60) {
    // cleanup after 15 minutes
    $now = time();
    $dir = new DirectoryIterator(CACHE);
    foreach ($dir as $info) {
      if (!$info->isDot())
        if ($now - $info->getMTime() > $since)
          @unlink($info->getFilename());
    }
  }
}