#Unicode true

!include filesize.nsi

!define IDENTITY "game"
!define TITLE "Game"
!define DESCRIPTION "Packaged by 2dengine.com"
!define PUBLISHER "2dengine"
!define URL "https://2dengine.com"
!define MAJOR 0
!define MINOR 0
!define BUILD 0

!define BINARY "${IDENTITY}.exe"
#!define INSTALLSIZE 1024

!define REGPATH "Software\Microsoft\Windows\CurrentVersion\Uninstall\${TITLE}\"
 
RequestExecutionLevel admin ;Require admin rights on NT6+ (When UAC is turned on)
 
InstallDir "$PROGRAMFILES\${TITLE}"

LicenseData "readme.txt"
Icon "logo.ico"
# This will be in the installer/uninstaller's title bar
Name "${TITLE}"
OutFile "${IDENTITY}-install.exe"

!include LogicLib.nsh
 
Page license
Page directory
Page instfiles
 
!macro VerifyUserIsAdmin
UserInfo::GetAccountType
pop $0
${If} $0 != "admin" ;Require admin rights on NT4+
  MessageBox mb_iconstop "Administrator rights required!"
  SetErrorLevel 740 ;ERROR_ELEVATION_REQUIRED
  Quit
${EndIf}
!macroend
 
function .onInit
	SetShellVarContext all
	!insertmacro VerifyUserIsAdmin
functionEnd

#!include "MUI2.nsh"
#!insertmacro MUI_PAGE_DIRECTORY
#!insertmacro MUI_PAGE_INSTFILES
#!insertmacro MUI_LANGUAGE English

Section "install"
	SetOutPath $INSTDIR  
  # Install
  File /r "*"
  WriteUninstaller "$INSTDIR\uninstall.exe"
	# Start Menu
	CreateShortcut "$SMPROGRAMS\${TITLE}.lnk" "$INSTDIR\${BINARY}" "" "$INSTDIR\logo.ico"
	#CreateDirectory "$SMPROGRAMS\${TITLE}"
	#CreateShortcut "$SMPROGRAMS\${TITLE}\${TITLE}.lnk" "$INSTDIR\${BINARY}" "" "$INSTDIR\logo.ico"
	#CreateShortcut "$SMPROGRAMS\${TITLE}\Uninstall.lnk" "$INSTDIR\uninstall.exe" "" ""
	#CreateShortcut "$SMPROGRAMS\${TITLE}\${PUBLISHER}.lnk" "${ABOUTURL}" "" ""
  # Desktop
  CreateShortcut "$DESKTOP\${TITLE}.lnk" "$INSTDIR\${BINARY}" "" "$INSTDIR\logo.ico"
	# Registry
	WriteRegStr HKLM "${REGPATH}" "DisplayName" "${TITLE}"
	WriteRegStr HKLM "${REGPATH}" "Comments" "${DESCRIPTION}"
	WriteRegStr HKLM "${REGPATH}" "Publisher" "${PUBLISHER}"
	WriteRegStr HKLM "${REGPATH}" "DisplayVersion" "${MAJOR}.${MINOR}.${BUILD}"
	WriteRegDWORD HKLM "${REGPATH}" "VersionMajor" ${MAJOR}
	WriteRegDWORD HKLM "${REGPATH}" "VersionMinor" ${MINOR}
	WriteRegStr HKLM "${REGPATH}" "URLUpdateInfo" "${URL}"
	#WriteRegStr HKLM "${REGPATH}" "HelpLink" "${URL}"
	#WriteRegStr HKLM "${REGPATH}" "URLInfoAbout" "${URL}"
	WriteRegStr HKLM "${REGPATH}" "DisplayIcon" "$INSTDIR\logo.ico"
	WriteRegStr HKLM "${REGPATH}" "InstallLocation" "$INSTDIR"
	WriteRegStr HKLM "${REGPATH}" "UninstallString" "$INSTDIR\uninstall.exe"
	WriteRegStr HKLM "${REGPATH}" "QuietUninstallString" "$\"$INSTDIR\uninstall.exe$\" /S"
	WriteRegDWORD HKLM "${REGPATH}" "NoModify" 1
	WriteRegDWORD HKLM "${REGPATH}" "NoRepair" 1

  ${GetSize} "$INSTDIR" "/S=0K" $0 $1 $2
	WriteRegDWORD HKLM "${REGPATH}" "EstimatedSize" $0
SectionEnd

function un.onInit
	SetShellVarContext all
	MessageBox MB_OKCANCEL "Would you like to uninstall ${TITLE}?" IDOK next
		Abort
	next:
	!insertmacro VerifyUserIsAdmin
functionEnd
 
Section "uninstall"
	RMDir /r /REBOOTOK $INSTDIR
	Delete "$SMPROGRAMS\${TITLE}.lnk"
	#RMDir /r /REBOOTOK "$SMPROGRAMS\${TITLE}"
	Delete "$DESKTOP\${TITLE}.lnk"
	DeleteRegKey HKLM "${REGPATH}"
SectionEnd
