@echo off
REM Binding Request Processor Control Script for Windows
REM Usage: scripts\binding-processor.bat {start|stop|status}

setlocal enabledelayedexpansion

set SCRIPT_DIR=%~dp0
set PROJECT_DIR=%SCRIPT_DIR%..
set PROCESSOR_SCRIPT=%PROJECT_DIR%\api\process-binding-requests.php
set PID_FILE=%PROJECT_DIR%\logs\binding-processor.pid
set LOG_FILE=%PROJECT_DIR%\logs\binding-processor.log

REM Ensure logs directory exists
if not exist "%PROJECT_DIR%\logs" mkdir "%PROJECT_DIR%\logs"

if "%1"=="start" goto start
if "%1"=="stop" goto stop
if "%1"=="status" goto status
goto usage

:start
    if exist "%PID_FILE%" (
        set /p PID=<"%PID_FILE%"
        tasklist /FI "PID eq !PID!" 2>NUL | find /I /N "php.exe">NUL
        if "!ERRORLEVEL!"=="0" (
            echo Binding processor is already running (PID: !PID!)
            exit /b 1
        ) else (
            echo Removing stale PID file
            del /F /Q "%PID_FILE%"
        )
    )
    
    echo Starting binding request processor...
    start /B php "%PROCESSOR_SCRIPT%" --daemon --interval=5 >> "%LOG_FILE%" 2>&1
    
    REM Get the PID of the started process
    for /f "tokens=2" %%a in ('tasklist /FI "IMAGENAME eq php.exe" /NH') do (
        set PID=%%a
        goto foundpid
    )
    
    :foundpid
    echo !PID! > "%PID_FILE%"
    echo Binding processor started (PID: !PID!)
    echo Log file: %LOG_FILE%
    exit /b 0

:stop
    if not exist "%PID_FILE%" (
        echo Binding processor is not running (no PID file found)
        exit /b 1
    )
    
    set /p PID=<"%PID_FILE%"
    
    tasklist /FI "PID eq %PID%" 2>NUL | find /I /N "php.exe">NUL
    if "%ERRORLEVEL%"=="1" (
        echo Binding processor is not running (process not found)
        del /F /Q "%PID_FILE%"
        exit /b 1
    )
    
    echo Stopping binding request processor (PID: %PID%)...
    taskkill /PID %PID% /F >NUL 2>&1
    
    if "%ERRORLEVEL%"=="0" (
        echo Binding processor stopped
        del /F /Q "%PID_FILE%"
        exit /b 0
    ) else (
        echo Failed to stop binding processor
        exit /b 1
    )

:status
    if not exist "%PID_FILE%" (
        echo Binding processor is not running (no PID file)
        exit /b 1
    )
    
    set /p PID=<"%PID_FILE%"
    
    tasklist /FI "PID eq %PID%" 2>NUL | find /I /N "php.exe">NUL
    if "%ERRORLEVEL%"=="0" (
        echo Binding processor is running (PID: %PID%)
        echo Log file: %LOG_FILE%
        exit /b 0
    ) else (
        echo Binding processor is not running (stale PID file)
        del /F /Q "%PID_FILE%"
        exit /b 1
    )

:usage
    echo Usage: %0 {start^|stop^|status}
    exit /b 1
