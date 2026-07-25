@echo off
setlocal

set "coverage=false"
for %%a in (%*) do (
    if "%%a"=="--coverage" set "coverage=true"
)

docker compose ps --status running --services | findstr /x "apache" >nul
if errorlevel 1 (
    echo Containers not running. Starting them...
    docker compose up -d
    echo Waiting for services to be ready...
    timeout /t 8 /nobreak >nul
)

if "%coverage%"=="true" (
    docker compose exec apache vendor/bin/phpunit --coverage-html coverage
) else (
    docker compose exec apache vendor/bin/phpunit
)

REM Open coverage report if generated
set "filePath=coverage\index.html"
if exist "%filePath%" (
    start "" "%filePath%"
)
