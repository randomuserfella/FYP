@echo off
title ProcraTrack Launcher
color 0A

echo.
echo  ================================================
echo   ProcraTrack - Auto Launcher
echo  ================================================
echo.

:: ── CONFIG — edit these two lines only ──────────────────────────────────────
set NGROK_PORT=80
set XAMPP_PATH=C:\xampp
:: ────────────────────────────────────────────────────────────────────────────

:: Check ngrok is installed
where ngrok >nul 2>&1
if errorlevel 1 (
    echo  [ERROR] ngrok not found. Make sure ngrok.exe is in your PATH.
    echo  Download from: https://ngrok.com/download
    pause
    exit /b 1
)

:: Check XAMPP Apache is running, start if not
echo  [1/4] Checking XAMPP Apache...
tasklist /fi "imagename eq httpd.exe" 2>nul | find /i "httpd.exe" >nul
if errorlevel 1 (
    echo        Starting Apache...
    start "" "%XAMPP_PATH%\apache\bin\httpd.exe"
    timeout /t 3 /nobreak >nul
) else (
    echo        Apache already running.
)

:: Kill any existing ngrok instance
taskkill /f /im ngrok.exe >nul 2>&1
timeout /t 1 /nobreak >nul

:: Start ngrok in background
echo  [2/4] Starting ngrok tunnel on port %NGROK_PORT%...
start "" /min ngrok http %NGROK_PORT%
echo        Waiting for tunnel to initialize...

:: Poll ngrok's local API instead of a fixed sleep — retry up to 15 times
:: (about 15 seconds), since ngrok startup time varies by machine.
set RETRIES=0
:WAIT_LOOP
timeout /t 1 /nobreak >nul
curl -s http://localhost:4040/api/tunnels > "%TEMP%\ngrok_tunnels.json" 2>nul
findstr /c:"public_url" "%TEMP%\ngrok_tunnels.json" >nul 2>&1
if not errorlevel 1 goto WAIT_DONE
set /a RETRIES+=1
if %RETRIES% lss 15 goto WAIT_LOOP

echo  [ERROR] ngrok did not become ready after 15 seconds.
echo  Try running this script again, or start ngrok manually first with:
echo    ngrok http %NGROK_PORT%
pause
exit /b 1

:WAIT_DONE
echo        Tunnel is up.

:: Run the PHP setup script to register webhook + update config
echo  [3/4] Fetching tunnel URL... done.
echo  [4/4] Registering Telegram webhook and updating config...

php "%~dp0ngrok_setup.php" "%TEMP%\ngrok_tunnels.json"

if errorlevel 1 (
    echo.
    echo  [ERROR] Setup script failed. Check that PHP is in your PATH.
    echo  Try: php -v  to verify.
    pause
    exit /b 1
)

echo.
echo  ================================================
echo   ProcraTrack is ready!
echo   Opening http://localhost/procratrack ...
echo  ================================================
echo.
timeout /t 2 /nobreak >nul
start "" "http://localhost/procratrack"

echo  Press any key to STOP ngrok and exit.
pause >nul

:: Cleanup on exit
taskkill /f /im ngrok.exe >nul 2>&1
echo  ngrok stopped. Goodbye!
timeout /t 2 /nobreak >nul
