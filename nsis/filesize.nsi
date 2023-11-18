# https://nsis.sourceforge.io/GetSize
# Thanks KiCHiK (Function "FindFiles")

Function GetSize
	!define GetSize `!insertmacro GetSizeCall`
 
	!macro GetSizeCall _PATH _OPTIONS _RESULT1 _RESULT2 _RESULT3
		Push `${_PATH}`
		Push `${_OPTIONS}`
		Call GetSize
		Pop ${_RESULT1}
		Pop ${_RESULT2}
		Pop ${_RESULT3}
	!macroend
 
	Exch $1
	Exch
	Exch $0
	Exch
	Push $2
	Push $3
	Push $4
	Push $5
	Push $6
	Push $7
	Push $8
	Push $9
	Push $R3
	Push $R4
	Push $R5
	Push $R6
	Push $R7
	Push $R8
	Push $R9
	ClearErrors
 
	StrCpy $R9 $0 1 -1
	StrCmp $R9 '\' 0 +3
	StrCpy $0 $0 -1
	goto -3
	IfFileExists '$0\*.*' 0 error
 
	StrCpy $3 ''
	StrCpy $4 ''
	StrCpy $5 ''
	StrCpy $6 ''
	StrCpy $8 0
	StrCpy $R3 ''
	StrCpy $R4 ''
	StrCpy $R5 ''
 
	option:
	StrCpy $R9 $1 1
	StrCpy $1 $1 '' 1
	StrCmp $R9 ' ' -2
	StrCmp $R9 '' sizeset
	StrCmp $R9 '/' 0 -4
 
	StrCpy $9 -1
	IntOp $9 $9 + 1
	StrCpy $R9 $1 1 $9
	StrCmp $R9 '' +2
	StrCmp $R9 '/' 0 -3
	StrCpy $8 $1 $9
	StrCpy $8 $8 '' 2
	StrCpy $R9 $8 '' -1
	StrCmp $R9 ' ' 0 +3
	StrCpy $8 $8 -1
	goto -3
	StrCpy $R9 $1 2
	StrCpy $1 $1 '' $9
 
	StrCmp $R9 'M=' 0 size
	StrCpy $4 $8
	goto option
 
	size:
	StrCmp $R9 'S=' 0 gotosubdir
	StrCpy $6 $8
	goto option
 
	gotosubdir:
	StrCmp $R9 'G=' 0 error
	StrCpy $7 $8
	StrCmp $7 '' +3
	StrCmp $7 '1' +2
	StrCmp $7 '0' 0 error
	goto option
 
	sizeset:
	StrCmp $6 '' default
	StrCpy $9 0
	StrCpy $R9 $6 1 $9
	StrCmp $R9 '' +4
	StrCmp $R9 ':' +3
	IntOp $9 $9 + 1
	goto -4
	StrCpy $5 $6 $9
	IntOp $9 $9 + 1
	StrCpy $1 $6 1 -1
	StrCpy $6 $6 -1 $9
	StrCmp $5 '' +2
	IntOp $5 $5 + 0
	StrCmp $6 '' +2
	IntOp $6 $6 + 0
 
	StrCmp $1 'B' 0 +4
	StrCpy $1 1
	StrCpy $2 bytes
	goto default
	StrCmp $1 'K' 0 +4
	StrCpy $1 1024
	StrCpy $2 Kb
	goto default
	StrCmp $1 'M' 0 +4
	StrCpy $1 1048576
	StrCpy $2 Mb
	goto default
	StrCmp $1 'G' 0 error
	StrCpy $1 1073741824
	StrCpy $2 Gb
 
	default:
	StrCmp $4 '' 0 +2
	StrCpy $4 '*.*'
	StrCmp $7 '' 0 +2
	StrCpy $7 '1'
 
	StrCpy $8 1
	Push $0
	SetDetailsPrint textonly
 
	nextdir:
	IntOp $8 $8 - 1
	Pop $R8
	FindFirst $0 $R7 '$R8\$4'
	IfErrors show
	StrCmp $R7 '.' 0 +5
	FindNext $0 $R7
	StrCmp $R7 '..' 0 +3
	FindNext $0 $R7
	IfErrors show
 
	dir:
	IfFileExists '$R8\$R7\*.*' 0 file
	IntOp $R5 $R5 + 1
	goto findnext
 
	file:
	StrCpy $R6 0
	StrCmp $5$6 '' 0 +3
	IntOp $R4 $R4 + 1
	goto findnext
	FileOpen $9 '$R8\$R7' r
	IfErrors +3
	FileSeek $9 0 END $R6
	FileClose $9
	StrCmp $5 '' +2
	IntCmp $R6 $5 0 findnext
	StrCmp $6 '' +2
	IntCmp $R6 $6 0 0 findnext
	IntOp $R4 $R4 + 1
	System::Int64Op /NOUNLOAD $R3 + $R6
	Pop $R3
 
	findnext:
	FindNext $0 $R7
	IfErrors 0 dir
	FindClose $0
 
	show:
	StrCmp $5$6 '' nosize
	System::Int64Op /NOUNLOAD $R3 / $1
	Pop $9
	DetailPrint 'Size:$9 $2  Files:$R4  Folders:$R5'
	goto subdir
	nosize:
	DetailPrint 'Files:$R4  Folders:$R5'
 
	subdir:
	StrCmp $7 0 preend
	FindFirst $0 $R7 '$R8\*.*'
	StrCmp $R7 '.' 0 +5
	FindNext $0 $R7
	StrCmp $R7 '..' 0 +3
	FindNext $0 $R7
	IfErrors +7
 
	IfFileExists '$R8\$R7\*.*' 0 +3
	Push '$R8\$R7'
	IntOp $8 $8 + 1
	FindNext $0 $R7
	IfErrors 0 -4
	FindClose $0
	StrCmp $8 0 0 nextdir
 
	preend:
	StrCmp $R3 '' nosizeend
	System::Int64Op $R3 / $1
	Pop $R3
	nosizeend:
	StrCpy $2 $R4
	StrCpy $1 $R5
	StrCpy $0 $R3
	goto end
 
	error:
	SetErrors
	StrCpy $0 ''
	StrCpy $1 ''
	StrCpy $2 ''
 
	end:
	SetDetailsPrint both
	Pop $R9
	Pop $R8
	Pop $R7
	Pop $R6
	Pop $R5
	Pop $R4
	Pop $R3
	Pop $9
	Pop $8
	Pop $7
	Pop $6
	Pop $5
	Pop $4
	Pop $3
	Exch $2
	Exch
	Exch $1
	Exch 2
	Exch $0
FunctionEnd