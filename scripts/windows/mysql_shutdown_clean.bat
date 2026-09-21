@echo off
REM ---------------------------------------------------------------------------
REM Stop MySQL cleanly.
REM
REM Use this INSTEAD of the XAMPP Control Panel's Stop button. That button runs
REM mysql_stop.bat, which calls killprocess.bat on mysqld.exe - a force-kill. A
REM killed mysqld leaves Aria's transaction log control file behind the tables
REM it describes, which is what produced "Table is from another system" on the
REM mysql grant tables and cost a day of recovery on 16 Sep 2026.
REM
REM Never force-kill MySQL. If this script reports anything other than
REM "OK: mysqld exited cleanly", investigate before doing anything else.
REM See docs/anti-cheat-plan.md, stage 11.
REM ---------------------------------------------------------------------------
setlocal

echo.
echo Asking MySQL to shut down...
echo.

"C:\xampp\mysql\bin\mysqladmin.exe" -u root shutdown
set _rc=%errorlevel%

if not "%_rc%"=="0" goto adminfailed

echo.
echo Waiting for mysqld.exe to exit, up to 30 seconds...

set /a _tries=0

:wait
call :isrunning
if "%_running%"=="0" goto gone
set /a _tries+=1
if %_tries% GEQ 30 goto stuck
ping -n 2 127.0.0.1 >nul
goto wait

:gone
echo.
echo OK: mysqld exited cleanly.
goto end

:stuck
echo.
echo STILL RUNNING after 30 s - investigate, do NOT force-kill.
goto end

REM --- mysqladmin returned non-zero: decide which case this is ---------------
:adminfailed
call :isrunning
if "%_running%"=="0" (
    echo.
    echo MySQL was not running - nothing to stop.
) else (
    echo.
    echo Shutdown refused - investigate, do NOT force-kill.
)
goto end

REM --- sets _running to 1 if mysqld.exe is present, 0 if not -----------------
:isrunning
set _running=0
tasklist /FI "IMAGENAME eq mysqld.exe" 2>nul | find /I "mysqld.exe" >nul
if not errorlevel 1 set _running=1
goto :eof

:end
echo.
pause
