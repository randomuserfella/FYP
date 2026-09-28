' ============================================================
' run_hidden.vbs
' Runs a command with zero visible window (unlike "start /min",
' which still flashes a window before minimizing).
' Usage: wscript.exe run_hidden.vbs "<command to run>"
' ============================================================
Set objShell = CreateObject("WScript.Shell")
strCmd = WScript.Arguments(0)
objShell.Run strCmd, 0, True
' 0 = hidden window, True = wait for command to finish
