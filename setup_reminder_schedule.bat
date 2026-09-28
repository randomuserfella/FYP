@echo off
:: ============================================================
:: ProcraTrack - Reminder Scheduler Setup (silent/hidden version)
:: Run this ONCE as Administrator to register the scheduled
:: tasks that trigger your reminders, WITHOUT flashing a
:: console window every time they run.
:: ============================================================

:: ── EDIT THESE TWO PATHS IF DIFFERENT ────────────────────────
set PHP_PATH=C:\xampp\php\php.exe
set APP_PATH=C:\xampp\htdocs\procratrack
:: ──────────────────────────────────────────────────────────────

set SCRIPT_DIR=%~dp0

echo Generating hidden-runner helper files...

:: Small launcher .bat files with the real paths baked in.
:: Keeping these separate avoids fragile nested-quote escaping
:: inside the schtasks command itself.
> "%SCRIPT_DIR%_run_send_reminders.bat" (
    echo @echo off
    echo "%PHP_PATH%" "%APP_PATH%\send_reminders.php"
)

> "%SCRIPT_DIR%_run_cron_push.bat" (
    echo @echo off
    echo "%PHP_PATH%" "%APP_PATH%\cron_push.php"
)

echo Removing any old versions of these tasks (safe if they don't exist)...
schtasks /Delete /TN "ProcraTrack_SendReminders" /F >nul 2>&1
schtasks /Delete /TN "ProcraTrack_CronPush" /F >nul 2>&1

echo.
echo Creating task: ProcraTrack_SendReminders (every 1 minute, hidden)
schtasks /Create /TN "ProcraTrack_SendReminders" ^
    /TR "wscript.exe \"%SCRIPT_DIR%run_hidden.vbs\" \"%SCRIPT_DIR%_run_send_reminders.bat\"" ^
    /SC MINUTE /MO 1 ^
    /RL LIMITED /F

echo.
echo Creating task: ProcraTrack_CronPush (daily at 08:00, hidden)
schtasks /Create /TN "ProcraTrack_CronPush" ^
    /TR "wscript.exe \"%SCRIPT_DIR%run_hidden.vbs\" \"%SCRIPT_DIR%_run_cron_push.bat\"" ^
    /SC DAILY /ST 08:00 ^
    /RL LIMITED /F

echo.
echo ============================================================
echo Done. No more console flash - tasks now run fully hidden.
echo Verify with:
echo   schtasks /Query /TN "ProcraTrack_SendReminders" /V /FO LIST
echo   schtasks /Query /TN "ProcraTrack_CronPush" /V /FO LIST
echo.
echo To confirm it's actually firing, watch this file grow:
echo   %APP_PATH%\logs\reminders.log
echo ============================================================
pause
