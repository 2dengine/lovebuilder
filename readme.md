# LÖVE builder
Building LÖVE projects for multiple platforms is difficult and often requires switching between operating systems.
This builder script can be installed on a web server in order to make the job easier.
The LÖVE builder will fuse and package your .love project files for distribution across multiple platforms.

## Requirements
The builder script needs to be installed on a 64-bit Apache server running on a Linux file system.
The server must be running PHP 8.0 or later and depends on the ZipArchive class.
Building your LÖVE project requires several binary tools that need to be manually installed on your server ("fuse" and "makensis").

Make sure that you have set executive permissions (0755) for the file "bin/appimagetool-x86_64.AppImage".
Please note that AppImageTool requires FUSE to run ("sudo apt-get install fuse").
makensis is also required in order to build the Windows installers ("sudo apt-get install nsis").
If any of the binaries fail to run, you will receive an error message describing the exact command.
For additional debugging information please try "sudo tail -100 /var/log/apache2/error.log"

## Usage
Make sure your .love file contains the following:

/conf.lua - Do not forget to set the title using: t.window.title = "My Game"

/logo.png - Application icon in PNG format (512x512 px)

/readme.txt - License agreement in plain text format

## Live Demo
https://2dengine.com/builder

## Credits
AppImageTool
https://github.com/AppImage/AppImageKit

Nullsoft Scriptable Install System (NSIS)
https://github.com/NSIS-Dev

PHP-ICO
https://github.com/chrisbliss18/php-ico/tree/master

SmellyFishstiks, Deceze and others
