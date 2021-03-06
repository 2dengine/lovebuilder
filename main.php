<?php

define('BIN', dirname(__FILE__).'/bin');
define('CACHE', dirname(__FILE__).'/tmp');

class Builder {
  public $version;
  public $project;
  public $icon;
  
  function __construct($love, $ver = '11.4', $proj) {
    if ($proj === null)
      $proj = pathinfo($love, PATHINFO_FILENAME);
    $this->project = $proj;
    $this->content = file_get_contents($love);
    
    $zip = new ZipArchive;
    if ($zip->open($love, ZipArchive::RDONLY) !== true)
      throw new ErrorException('Love file is not a zip archive:'.$love);
    if (!$zip->locateName('main.lua'))
      throw new ErrorException('Cannot locate main.lua:'.$love);
    $zip->close();
    
    if ($ver != '11.3' and $ver != '11.4')
      throw new ErrorException('Unsupported Love2D version:'.$ver);
    $this->version = $ver;
  }
  
  function exportWindows($icon, $bits = 64) {
    if ($bits != 32 and $bits != 64)
      throw new ErrorException('Invalid platform architecture');
    // copy zipped binaries
    $bins = BIN.'/love-'.$this->version.'-win'.$bits.'.zip';
    $out = tempnam(CACHE, 'zip');
    copy($bins, $out);
    $proj = $this->project;
    $cont = $this->content;
    $src = new ZipArchive;
    $src->open($out);
    // fuse
    $old = $src->getFromName('love.exe');
    $src->deleteName('love.exe');
    // re-compress
    $src->addFromString($proj.'.exe', $old.$cont);
    $src->close();
    return $out;
  }
  
  function exportLinux($icon) {
    // extract zipped app image
    $bins = BIN.'/love-'.$this->version.'-linux.zip';
    $squash = CACHE.'/'.uniqid(rand(), true);
    mkdir($squash);
    /*
    $src = new ZipArchive;
    $src->open($bins);
    $src->extractTo($squash);
    $src->close();
    */
    exec("unzip $bins -d $squash");
    // fuse
    $proj = $this->project;
    $cont = $this->content;
    $bin = ($this->version == '11.4') ? $squash.'/bin' : $squash.'/usr/bin';
    $old = file_get_contents($bin.'/love');
    unlink($bin.'/love');
    file_put_contents($bin.'/'.$proj, $old.$cont);
    // make executable
    $dir = new DirectoryIterator($bin);
    foreach ($dir as $fileinfo)
      if (!$fileinfo->isDot())
        exec('chmod +x '.$bin.'/'.$fileinfo->getFilename());
    exec('chmod +x '.$squash.'/AppRun');
    
    if (!is_executable($bin.'/'.$proj))
      throw new ErrorException('Could not make binaries executable');
    
    // information
    $info = file_get_contents($squash.'/love.desktop');
    $info = preg_replace("/Name=[^\n]*?\n/", "Name=$proj\n", $info, 1);
    $info = preg_replace("/Exec=[^\n]*?\n/", "Exec=$proj %f\n", $info, 1);
    file_put_contents($squash.'/love.desktop', $info);
    // icon
    if ($icon) {
      $mime = mime_content_type($icon);
      if ($mime == 'image/svg+xml' or $mime == 'image/svg' or $mime == 'image/png') {
        $iconcont = file_get_contents($icon);
        unlink($squash.'/love.svg');
        file_put_contents($squash.'/love.'.$iconext, $iconcont);
      } else {
        throw new ErrorException('Linux application icon must be in .PNG or .SVG format');
      }
    }

    $out = tempnam(CACHE, 'img');
    exec(BIN.'/appimagetool-x86_64.AppImage '.$squash.' '.$out);
    exec('rm '.$squash.' -r');
    if (!file_exists($out))
      throw new ErrorException('Could not build AppImage');

    return $out;
  }
  
  function exportMacOS($icon) {
    $out = tempnam(CACHE, 'app');
    $bins = BIN.'/love-'.$this->version.'-macos.zip';
    copy($bins, $out);
    $proj = $this->project;
    $cont = $this->content;
    $version = $this->version;
    
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
    // icon
    if ($icon) {
      //$iconext = strtolower($icon, PATHINFO_EXTENSION);
      $mime = mime_content_type($icon);
      $iconcont = file_get_contents($icon);
      if ($mime == 'image/x-icns') {
        $src->addFromString('love.app/Contents/Resources/GameIcon.icns', $iconcont);
        $src->addFromString('love.app/Contents/Resources/OS X AppIcon.icns', $iconcont);
      } elseif ($mime == 'image/png') {
        $src->addFromString('love.app/.Icon\r', $iconcont);
      } else {
        throw new ErrorException('MacOS application icon must be in .ICNS or .PNG format');
      }
    }
    // rename
    // thanks to https://stackoverflow.com/users/476/deceze
    for( $i = 0; $i < $src->numFiles; $i++ ){ 
      $stat = $src->statIndex($i);
      if (!$stat)
        continue;
      $fn = $stat['name'];
      $src->renameName($fn, str_replace('love.app', $proj.'.app', $fn));
    }
    $src->close();
    return $out;
  }
  
  function exportWeb($icon) {
    $bins = BIN.'/love-'.$this->version.'-web.zip';
    $out = tempnam(CACHE, 'web');
    copy($bins, $out);
    $proj = $this->project;
    $cont = $this->content;
    $src = new ZipArchive;
    $src->open($out);
    $src->addFromString($proj.'.love', $cont);
    $player = $src->getFromName('player.js');
    $player = str_replace('game.love', $proj.'.love', $player);
    $src->addFromString('player.js', $player);
    $src->close();
    return $out;
  }
  
  function export($platform, $icon, $bits = 64) {
    $out = null;
    if ($platform == 'win32') {
      $out = $this->exportWindows($icon, 32);
    } elseif ($platform == 'win64') {
      $out = $this->exportWindows($icon, 64);
    } elseif ($platform == 'linux') {
      $out = $this->exportLinux($icon);
    } elseif ($platform == 'macos') {
      $out = $this->exportMacOS($icon);
    } elseif ($platform == 'web') {
      $out = $this->exportWeb($icon);
    } else {
      throw new ErrorException('Unsupported target platform');
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