# LÖVE builder
Building LÖVE projects for multiple platforms is difficult and often requires switching between operating systems.
This builder script can be installed on a web server in order to make the job easier.
The LÖVE builder will fuse and package your .love project files for distribution across multiple platforms.

## Requirements
The builder script needs to be installed on a 64-bit Apache server running on a Linux file system.
The server must be running PHP 8.0 or later and depends on the ZipArchive class.
The builder also uses AppImageTool which may require FUSE to run ("sudo apt install libfuse2").
makensis is required in order to build Windows installers.

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
