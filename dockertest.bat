@echo off
setlocal enabledelayedexpansion

set NOBROWSER=false
set COVERAGE=false
set TESTDOX=false
set PASSTHROUGH=

:loop
if "%~1"=="" goto after_loop
if "%~1"=="--nobrowser" (
    set NOBROWSER=true
) else if "%~1"=="--coverage" (
    set COVERAGE=true
) else if "%~1"=="--testdox" (
    set TESTDOX=true
) else (
    :: Re-add quotes if necessary, though simpler to just concatenate
    set PASSTHROUGH=!PASSTHROUGH! %1
)
shift
goto loop

:after_loop

:: Check if containers are running
docker-compose ps | findstr /R /C:"pramnos_php.*Up" >nul
if errorlevel 1 (
    echo Containers not running. Starting them...
    docker-compose up -d
    echo Waiting for services to be ready...
    timeout /t 10 /nobreak >nul
)

:: Check if database is initialized by accessing the app
echo Ensuring database is initialized...
curl -s http://localhost:18081/ >nul 2>&1

:: Ensure dependencies are installed
docker-compose exec php-apache-environment test -f vendor/bin/phpunit >nul 2>&1
if errorlevel 1 (
    echo Installing composer dependencies...
    docker-compose exec php-apache-environment composer install
)

:: Run tests
set EXTRA_FLAGS=--display-deprecations --display-warnings --display-notices
if "%TESTDOX%"=="true" (
    set EXTRA_FLAGS=!EXTRA_FLAGS! --testdox
)

if "%COVERAGE%"=="true" (
    docker-compose exec php-apache-environment vendor/bin/phpunit --coverage-html coverage %EXTRA_FLAGS% !PASSTHROUGH!
) else (
    docker-compose exec php-apache-environment vendor/bin/phpunit %EXTRA_FLAGS% !PASSTHROUGH!
)

:: Open coverage report if generated and not suppressed
if "%COVERAGE%"=="true" (
    if "%NOBROWSER%"=="false" (
        if exist coverage\index.html (
            start "" "coverage\index.html"
        )
    )
)
