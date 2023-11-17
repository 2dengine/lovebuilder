# LÖVE builder
Building LÖVE projects for multiple platforms is difficult and often requires switching between operating systems.
This builder script can be installed on a web server in order to make the job easier.
The LÖVE builder will fuse and package your .love project files for distribution across multiple platforms.

## Requirements
The builder script needs to be installed on a 64-bit Apache server running on a Linux file system.
The server must be running PHP 8.0 or later and depends on the ZipArchive class.
The builder also uses AppImageTool which may require FUSE to run ("sudo apt install libfuse2").

## Live Demo
https://2dengine.com/builder

## Credits
AppImageTool binary from
https://github.com/AppImage/AppImageKit

SmellyFishstiks, Deceze and others
