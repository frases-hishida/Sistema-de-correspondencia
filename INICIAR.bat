@echo off
chcp 65001 >nul
title TRAMITE - Servidor local
cd /d "%~dp0"

if not exist "index.php" (
    echo  [X] No encontre index.php junto a este archivo.
    pause & exit /b 1
)

set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP (
    for /d %%D in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP*") do (
        if not defined PHP for /r "%%D" %%P in (php.exe) do if not defined PHP set "PHP=%%P"
    )
)
if not defined PHP (
    echo  No encontre PHP. Instalando con winget...
    winget install --id PHP.PHP.8.3 -e --accept-source-agreements --accept-package-agreements
    if exist "%LOCALAPPDATA%\Microsoft\WinGet\Links\php.exe" set "PHP=%LOCALAPPDATA%\Microsoft\WinGet\Links\php.exe"
)
if not defined PHP ( echo  [X] PHP no disponible. & pause & exit /b 1 )

echo  PHP: %PHP%
set "PORT="
for %%P in (8000 8080 8090 8888 3000) do (
    if not defined PORT (
        netstat -ano 2>nul | findstr /C:":%%P " | findstr "LISTENING" >nul
        if errorlevel 1 set "PORT=%%P"
    )
)
if not defined PORT ( echo  [X] Puertos ocupados. & pause & exit /b 1 )

echo  Servidor: http://localhost:%PORT%  (cierra esta ventana para detener)
start "" cmd /c "timeout /t 2 /nobreak >nul & start "" http://localhost:%PORT%/"

"%PHP%" -S localhost:%PORT%
pause