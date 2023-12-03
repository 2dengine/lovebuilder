# LÖVE builder
Building LÖVE projects for multiple platforms is difficult and often requires switching between operating systems.
This builder script can be installed on a web server in order to make the job easier.
The LÖVE builder will fuse and package your .love project files for distribution across multiple platforms.

## Requirements
The builder script needs to be installed on a 64-bit Apache server running on a Linux file system.
The server must be running PHP 8.0 or later and depends on several additional binary tools that need to be manually installed on your server:

### zip, unzip
In addition to the ZipArchive class for PHP, your server needs to support the "zip" and "unzip" commands.

### makensis
makensis is required in order to build the Windows installers ("sudo apt-get install nsis").

### genisoimage
genisoimage is required to package files compatible with the MacOS file system.

### AppImageTool
Make sure that you have set executive permissions (0755) for the file "bin/appimagetool-x86_64.AppImage".
Please note that AppImageTool requires "fuse" to run ("sudo apt-get install fuse").

If any of the binaries fail to run, you will receive an error message describing the exact command.
For additional debugging information please try "sudo tail -100 /var/log/apache2/error.log"

## Usage
Make sure your .love file contains the following:

/meta.txt - Application metadata to be used between platforms. The app metadata must be in .ini format:

```
title=Awesome Game
comment=Packaged by 2dengine.com
publisher=2dengine LLC
url=https://2dengine.com/
major=1
minor=0
build=0
```

/logo.png - The application icon in PNG format (512x512 px). The PNG file will be automatically resized and converted to .ico on Windows and .icns on MacOS.

/readme.txt - License agreement in plain text format

## Live Demo
https://2dengine.com/builder

## Credits
AppImageTool
https://github.com/AppImage/AppImageKit

Nullsoft Scriptable Install System (NSIS)
https://github.com/NSIS-Dev

genisoimage
https://linux.die.net/man/1/genisoimage

PHP-ICO
https://github.com/chrisbliss18/php-ico/tree/master

uckelman from StackOverflow, SmellyFishstiks, Deceze, The Love2D community and LuaScripters on Discord
