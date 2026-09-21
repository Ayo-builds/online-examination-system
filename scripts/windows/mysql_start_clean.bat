@echo off
REM ---------------------------------------------------------------------------
REM Start MySQL cleanly.
REM
REM Use this INSTEAD of the XAMPP Control Panel's Start button.
REM
REM Why: a mysqld started by the Control Panel has no valid stdout/stderr. On
REM teardown Windows appears to close whatever landed on descriptors 0-2 - which
REM can be an Aria file - so the shutdown fails with
REM   Error writing file 'aria_log_control' (Errcode: 9 "Bad file descriptor")
REM   Aria engine: checkpoint failed
REM Observed on 21 Sep 2026: both Control-Panel-started instances failed their
REM Aria checkpoint on shutdown; the one started this way did not.
REM
REM This script hands mysqld real file handles for stdout and stderr before it
REM opens anything else, and starts it detached so closing this window is safe.
REM Stop it with mysql_shutdown_clean.bat, never the Control Panel.
REM See docs/anti-cheat-plan.md, stage 11.
REM ---------------------------------------------------------------------------
setlocal

tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if not errorlevel 1 (
    echo.
    echo MySQL is already running.
    goto end
)

if not exist "C:\xampp-backups\mysql-logs" mkdir "C:\xampp-backups\mysql-logs"

echo.
echo Starting MySQL...
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "$t=Get-Date -Format 'yyyyMMdd-HHmmss'; $d='C:\xampp-backups\mysql-logs'; Start-Process -FilePath 'C:\xampp\mysql\bin\mysqld.exe' -ArgumentList '--defaults-file=c:\xampp\mysql\bin\my.ini','--standalone' -RedirectStandardOutput ($d+'\mysqld-out-'+$t+'.log') -RedirectStandardError ($d+'\mysqld-err-'+$t+'.log') -WindowStyle Hidden"

echo Waiting for MySQL to answer, up to 30 seconds...

set /a _tries=0

:wait
"C:\xampp\mysql\bin\mysqladmin.exe" --connect-timeout=2 -u root ping >nul 2>&1
if not errorlevel 1 goto up
set /a _tries+=1
if %_tries% GEQ 30 goto failed
ping -n 2 127.0.0.1 >nul
goto wait

:up
echo.
echo OK: MySQL is running.
goto end

:failed
echo.
echo FAILED to start - check the Application log.
goto end

:end
echo.
pause
