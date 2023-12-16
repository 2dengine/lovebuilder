<?php
namespace dengine;

use ZipArchive;
use ErrorException;
use DirectoryIterator;

class LoveBuild {
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
    $output = $code = null;
    exec($cmd, $output, $code);
    if ($code) {
      $user = exec('whoami');
      $this->error("The following command failed: sudo -u $user $cmd", 503);
    }
  }
  
  protected function append($a, $b) {
    $src = fopen($a, 'r');
    $dest = fopen($b, 'ab');
    stream_copy_to_stream($src, $dest);
    fclose($src);
    fclose($dest);
  }
  
  /*
   * Exports the love project to Microsoft Windows as an EXE installer
   * @param $ops Options array
   */
  protected function exportWindows($ops) {
    $out = $ops['dest'];
    // fuse executable
    $tmp = $ops['tmp'];
    $project = $ops['project'];
    rename("$tmp/love.exe", "$tmp/$project.exe");
    $this->append($ops['src'], "$tmp/$project.exe");

    // NSIS installer configuration
    $bits = ($ops['platform'] == 'win32') ? 32 : 64;
    $nsis = __DIR__.'/nsis';
    $info = file_get_contents("$nsis/installer.nsi");
    $meta = $ops['meta'];
    $array = [
      'IDENTITY' => $project,
      'TITLE' => $meta['title'],
      'DESCRIPTION' => $meta['comment'],
      'PUBLISHER' => $meta['publisher'],
      'URL' => $meta['url'],
      'MAJOR' => $meta['major'],
      'MINOR' => $meta['minor'],
      'BUILD' => $meta['build'],
    ];
    foreach ($array as $k => $v) {
      if (is_null($v))
        continue;
      if (is_string($v)) {
        // ([^$"]|$\"|$$)*"
        $v = str_replace('$', '$$', $v);
        $v = str_replace('"', '$\\"', $v);
        $v = '"'.$v.'"';
      }
      $info = preg_replace("/!define $k [^\n]*?\n/", "!define $k $v\n", $info, 1);
    }
    $info = str_replace('$PROGRAMFILES', '$PROGRAMFILES'.$bits, $info);

    file_put_contents("$tmp/installer.nsi", $info);
    copy("$nsis/filesize.nsi", "$tmp/filesize.nsi");
    
    // license agreement
    if (!is_file("$tmp/readme.txt"))
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
   * @param $ops Options array
   */
  protected function exportLinux($ops) {
    $tmp = $ops['tmp'];
    $out = $ops['dest'];
    /*
    // fuse executable
    $appimg = $tmp.'/love-'.$ops['version'].'-x86_64.AppImage';
    $this->exec("chmod +x $appimg");
    $this->exec("cd $tmp && $appimg --appimage-extract");
    $squash = "$tmp/squashfs-root";
    */
    $squash = $tmp;
    $sqbin = (is_dir("$squash/bin")) ? "$squash/bin" : "$squash/usr/bin";
    $sqbin = realpath($sqbin);
    if (!is_dir($sqbin)) {
      $this->error('The project binaries were not found', 503);
      return;
    }
    $project = $ops['project'];
    //rename("$sqbin/love", "$sqbin/$project");
    //$this->append($ops['src'], "$sqbin/$project");
    $this->append($ops['src'], "$sqbin/love");
    // permissions
    $dir = new DirectoryIterator($sqbin);
    foreach ($dir as $fileinfo)
      if (!$fileinfo->isDot())
        $this->exec('chmod +x '.$sqbin.'/'.$fileinfo->getFilename());
    $this->exec("chmod +x $squash/AppRun");
    //if (!is_executable("$sqbin/$project")) {
    if (!is_executable("$sqbin/love")) {
      $this->error('The project binaries cannot be fused', 503);
      return;
    }

    // .desktop file metadata
    $info = file_get_contents("$squash/love.desktop");
    unlink("$squash/love.desktop");
    $meta = $ops['meta'];
    $array = [
      //'Exec' => $project,
      'Exec' => 'love',
      'Name' => $meta['title'],
      'Comment' => $meta['comment'],
      'Categories' => 'Game;',
      'Icon' => $project,
      'NoDisplay' => 'false',
      'Terminal' => 'false',
    ];
    foreach ($array as $k => $v) {
      if (is_null($v))
        continue;
      if (is_string($v))
        $v = str_replace(PHP_EOL, " ", $v);
      $info = preg_replace("/$k=[^\n]*?\n/", "$k=$v\n", $info, 1);
    }
    file_put_contents("$squash/$project.desktop", $info);

    // application icon
    rename("$squash/love.svg", "$squash/$project.svg");
    $icon = $ops['icon'];
    if (is_file($icon)) {
      unlink("$squash/$project.svg");
      $data = file_get_contents($icon);
      file_put_contents("$squash/.DirIcon", $data);
      file_put_contents("$squash/$project.png", $data);
    }

    // build
    $appimgtool = realpath($this->bin.'/appimagetool.AppImage');
    if (!is_executable($appimgtool)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    $this->exec("$appimgtool $squash $out");
  }
  
  /*
   * Exports the love project to MacOS
   * @param $ops Options array
   */
  protected function exportMacOS($ops) {
    // fuse executable
    $tmp = $ops['tmp'];
    $project = $ops['project'];
    copy($ops['src'], "$tmp/love.app/Contents/Resources/$project.love");
    
    $meta = $ops['meta'];
    // information
    $array = [
      'CFBundleName' => $project,
      'CFBundleShortVersionString' => $meta['version'],
      'NSHumanReadableCopyright' => $meta['comment'],
      //'UTExportedTypeDeclarations' => false,
    ];
    $info = file_get_contents("$tmp/love.app/Contents/Info.plist");
    foreach ($array as $k => $v) {
      if (is_null($v))
        continue;
      if (is_string($v))
        $v = htmlentities($v);
      $info = preg_replace("/<key>$k<\/key>[\s]*?<string>[\s\S]*?<\/string>/", "<key>$k</key>\n\t<string>$v</string>", $info, 1);
    }
    file_put_contents("$tmp/love.app/Contents/Info.plist", $info);
    
    /*
    // icon
    $icon = $ops['icon'];
    if (is_file($icon)) {
      $data = file_get_contents($icon);
      //file_put_contents("$tmp/love.app/.Icon\r", $data);
      file_put_contents("$tmp/love.app/Contents/Resources/GameIcon.icns", $data);
      file_put_contents("$tmp/love.app/Contents/Resources/OS X AppIcon.icns", $data);
    }
    */
    rename("$tmp/love.app", "$tmp/$project.app");
    
    $out = $ops['dest'];    
    $this->exec("genisoimage -V \"$project\" -D -R -apple -no-pad -o $out $tmp");
  }

  /*
   * Exports the love project to Linux as an AppImage
   * @param $ops Options array
   */
  protected function exportAndroid($ops) {
    $tmp = $ops['tmp'];

    $src = $ops['src'];
    @mkdir("$tmp/assets/");
    if ($ops['version'] == '11.5') {
      //$this->exec("unzip -o $src -d $tmp/assets/");      
      $zip = new ZipArchive();
      $zip->open($src, ZipArchive::RDONLY);
      $zip->extractTo("$tmp/assets/");
      $zip->close();
    } else {
      copy($src, "$tmp/assets/game.love");
    }
    
    $meta = $ops['meta'];
    // the greatest value Google Play allows for versionCode is 2100000000
    $code = substr($meta['major'].$meta['minor'].$meta['build'], 0, 9);
    //$code = min($code, 2100000000);
    
    $package = preg_replace('/[^a-zA-Z0-9]/', '', $ops['project']);
    $package = preg_replace('/^[0-9]+/', '', $package);
    $package = 'com.dengine.'.$package;
    // manifest file metadata
    $manifest = file_get_contents("$tmp/AndroidManifest.xml");
    $array = [
      'package' => $package,
      'platformBuildVersionCode' => $code,
      'platformBuildVersionName' => $meta['version'],
      'android:label' => $meta['title'],
      'android:authorities' => "$package.androidx-startup",
    ];
    foreach ($array as $k => $v) {
      if (is_null($v))
        continue;
      if (is_string($v))
        $v = str_replace(PHP_EOL, " ", $v);
      $v = str_replace('"', '$\\"', $v);
      $manifest = preg_replace("/$k=\"[^\n]*?\"/", "$k=\"$v\"", $manifest);
    }
    file_put_contents("$tmp/AndroidManifest.xml", $manifest);
    
    $yml = file_get_contents("$tmp/apktool.yml");
    $array = [
      'apkFileName' => $ops['project'].'.apk',
      //'targetSdkVersion' => 29,
      'versionCode' => $code,
      'versionName' => $meta['version'],
    ];
    foreach ($array as $k => $v) {
      if (is_null($v))
        continue;
      if (is_string($v))
        $v = str_replace(PHP_EOL, " ", $v);
      $yml = preg_replace("/$k:[^\n]*?\n/", "$k: $v\n", $yml, 1);
    }
    file_put_contents("$tmp/apktool.yml", $yml);

    // application icon
    $icon = $ops['icon'];
    if (is_file($icon)) {
      $icons = [
        'hdpi' => 72,
        'mdpi' => 72,
        'xhdpi' => 96,
        'xxhdpi' => 96,
        'xxxhdpi' => 192
      ];
      $src = imagecreatefrompng($icon);
      list($width, $height) = getimagesize($icon);
      foreach ($icons as $k => $size) {
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, true);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $size, $size, $width, $height);
        imagepng($img, "$tmp/res/drawable-$k/love.png");
      }
    }

    // build
    $apktool = realpath($this->bin.'/apktool');
    if (!is_executable($apktool)) {
      $this->error('The project binaries cannot be processed', 503);
      return;
    }
    $out = $ops['dest'];
    $frame = realpath($tmp.'/../');
    $this->exec("$apktool b $tmp -o $out -p $frame");
  }

  /*
   * Exports a previously uploaded file based on a handle
   * @param $src Path to the .love file to be read
   * @param $dest Path to the resulting binary file to be written
   * @param $platform Target platform
   * @param $project Project name, if different from the source filename
   * @param $version Love2D version
   * @return True if successful
   */
  protected function exportFile($src, $dest, $platform, $project, $version) {
    if (!$project)
      $project = pathinfo($src, PATHINFO_FILENAME);
    if (!$version)
      $version = '11.5';
    $identity = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', $project);
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
    
    // binaries
    $bin = $this->bin."/love-$version-$platform.zip";
    if (!is_file($bin)) {
      $this->error('The specified platform and version are unsupported', 400);
      return false;
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

    // metadata from meta.txt
    $ops = [
      'project' => strtolower($identity),
      'platform' => $platform,
      'version' => $version,
      'src' => $src,
      'bin' => $bin,
      'dest' => $dest,
      'icon' => $icon,
      'meta' => [
        'title' => $project,
        'publisher' => '2dengine.com',
        'comment' => 'Packaged by 2dengine.com',
        'url' => 'https://2dengine.com',
        'major' => 1,
        'minor' => 0,
        'build' => '0'.date('ymd'),
      ]
    ];
    $ini = $zip->getFromName('meta.txt');
    if ($ini) {
      $data = parse_ini_string($ini);
      foreach ($data as $k => $v)
        $ops['meta'][$k] = $v;
    }
    $meta = $ops['meta'];
    if ($meta['major'] > 9 or $meta['minor'] > 9) {
      $this->error('The application version is out of range', 400);
      return false;
    }
    if (!isset($meta['version']) or !$meta['version'])
      $ops['meta']['version'] = $meta['major'].'.'.$meta['minor'].'.'.$meta['build'];
    $readme = $zip->getFromName('readme.txt');
    $zip->close(); 

    $tmp = $ops['dest'].'_tmp';
    $this->rmdir($tmp);
    //mkdir("$tmp/");
    // ZipArchive ruins our symlinks so we have to use "unzip"
    //if ($platform != 'macos') {
      $ops['tmp'] = $tmp;
      $this->exec("unzip -o $bin -d $tmp/");
    //}
    if ($readme)
      file_put_contents("$tmp/readme.txt", $readme);

    if ($platform == 'android')
      $this->exportAndroid($ops);
    elseif ($platform == 'win32' or $platform == 'win64')
      $this->exportWindows($ops);
    elseif ($platform == 'linux')
      $this->exportLinux($ops);
    elseif ($platform == 'macos')
      $this->exportMacOS($ops);
    
    $this->rmdir($tmp);
    
    if (!is_file($dest) or !filesize($dest)) {
      $this->error('The project could not be exported to the specified location', 503);
      return false;
    }
    return true;
  }
}
