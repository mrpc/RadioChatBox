@echo off
REM RadioChatBox Startup Script for Windows
REM This script helps you quickly start, stop, and manage RadioChatBox

setlocal

if "%1"=="" goto start
if /i "%1"=="start" goto start
if /i "%1"=="stop" goto stop
if /i "%1"=="restart" goto restart
if /i "%1"=="status" goto status
if /i "%1"=="logs" goto logs
if /i "%1"=="build" goto build
if /i "%1"=="backup" goto backup
if /i "%1"=="clean" goto clean
if /i "%1"=="help" goto help
if /i "%1"=="-h" goto help
if /i "%1"=="--help" goto help

echo Unknown command: %1
echo.
goto help

:start
echo Starting RadioChatBox...
echo.

if not exist .env (
    echo Warning: .env file not found. Creating from .env.example...
    copy .env.example .env
    echo .env file created. You may want to edit it before continuing.
    echo.
)

docker-compose up -d

echo.
echo RadioChatBox is starting up!
echo.
echo Please wait 30-60 seconds for all services to be ready.
echo.
echo Then open your browser to: http://localhost:98
echo.
echo To view logs: start.bat logs
echo To stop: start.bat stop
goto end

:stop
echo Stopping RadioChatBox...
docker-compose down
echo RadioChatBox stopped.
goto end

:restart
echo Restarting RadioChatBox...
docker-compose restart
echo RadioChatBox restarted.
goto end

:status
echo RadioChatBox Status:
docker-compose ps
goto end

:logs
docker-compose logs -f --tail=100
goto end

:build
echo Rebuilding RadioChatBox...
docker-compose down
docker-compose build --no-cache
docker-compose up -d
echo RadioChatBox rebuilt and started.
goto end

:backup
set BACKUP_DIR=backups
set TIMESTAMP=%date:~-4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set TIMESTAMP=%TIMESTAMP: =0%

if not exist %BACKUP_DIR% mkdir %BACKUP_DIR%

echo Creating backup...

docker exec radiochatbox_postgres pg_dump -U radiochatbox radiochatbox > "%BACKUP_DIR%\database_%TIMESTAMP%.sql"
docker exec radiochatbox_redis redis-cli --rdb /data/dump.rdb
docker cp radiochatbox_redis:/data/dump.rdb "%BACKUP_DIR%\redis_%TIMESTAMP%.rdb"

echo Backup created in %BACKUP_DIR%\
goto end

:clean
echo WARNING: This will remove all containers, volumes, and data!
set /p confirm="Are you sure? (yes/no): "

if /i "%confirm%"=="yes" (
    echo Cleaning RadioChatBox...
    docker-compose down -v
    echo RadioChatBox cleaned.
) else (
    echo Cancelled.
)
goto end

:help
echo RadioChatBox Management Script
echo.
echo Usage: start.bat [command]
echo.
echo Commands:
echo   start     - Start RadioChatBox (default)
echo   stop      - Stop RadioChatBox
echo   restart   - Restart RadioChatBox
echo   status    - Show status of all services
echo   logs      - View logs (Ctrl+C to exit)
echo   build     - Rebuild all containers
echo   backup    - Backup database and Redis data
echo   clean     - Remove all containers and data (WARNING: destructive)
echo   help      - Show this help message
echo.
goto end

:end
endlocal
