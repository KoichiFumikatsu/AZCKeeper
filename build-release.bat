@echo off
setlocal enabledelayedexpansion
chcp 65001 > nul

echo ========================================
echo   AZCKeeper Build Script v2.0
echo   Update Package (Framework-Dependent)
echo ========================================

:: VERSION: pasar como argumento (build-release.bat 3.9.0.1) o usar el default de abajo.
set VERSION=%~1
if "%VERSION%"=="" set VERSION=3.9.0.0
set BUILD_DIR=%~dp0build
set CONFIG=Release
set RUNTIME=win-x64

echo.
echo [1/5] Limpiando builds anteriores...
if exist "%BUILD_DIR%" rmdir /s /q "%BUILD_DIR%"
mkdir "%BUILD_DIR%"
mkdir "%BUILD_DIR%\package"

echo.
echo [2/5] Compilando Updater (self-contained + single-file)...
cd AZCKeeperUpdater
dotnet publish -c %CONFIG% -r %RUNTIME% ^
  --self-contained true ^
  -p:PublishSingleFile=true ^
  -p:PublishTrimmed=true ^
  -p:Version=%VERSION% ^
  -o "%BUILD_DIR%\updater" ^
  --nologo -v minimal
if errorlevel 1 (
    echo ERROR: Fallo al compilar Updater
    pause
    exit /b 1
)
cd ..

echo.
echo [3/5] Compilando Cliente (self-contained + optimizado)...
cd AZCKeeper_Client
dotnet publish -c %CONFIG% -r %RUNTIME% ^
  --self-contained true ^
  -p:PublishSingleFile=false ^
  -p:PublishReadyToRun=true ^
  -p:PublishTrimmed=false ^
  -p:Version=%VERSION% ^
  -o "%BUILD_DIR%\package" ^
  --nologo -v minimal
if errorlevel 1 (
    echo ERROR: Fallo al compilar Cliente
    pause
    exit /b 1
)
cd ..

echo.
echo [4/5] Copiando Updater al package...
copy /Y "%BUILD_DIR%\updater\AZCKeeperUpdater.exe" "%BUILD_DIR%\package\"
if errorlevel 1 (
    echo ERROR: No se pudo copiar AZCKeeperUpdater.exe
    pause
    exit /b 1
)

copy /Y "%~dp0install.bat" "%BUILD_DIR%\package\"
if errorlevel 1 (
    echo ERROR: No se pudo copiar install.bat
    pause
    exit /b 1
)

copy /Y "%~dp0azc-killer.ps1" "%BUILD_DIR%\package\"
if errorlevel 1 (
    echo ERROR: No se pudo copiar azc-killer.ps1
    pause
    exit /b 1
)

:: Limpiar archivos de debug innecesarios en el paquete
del /f /q "%BUILD_DIR%\package\*.pdb" 2>nul

echo.
echo [5/5] Creando ZIP de distribución...
powershell -NoProfile -Command "Compress-Archive -Path '%BUILD_DIR%\package\*' -DestinationPath '%BUILD_DIR%\AZCKeeper_v%VERSION%.zip' -Force"
if errorlevel 1 (
    echo ERROR: No se pudo crear el ZIP
    pause
    exit /b 1
)

echo.
echo ========================================
echo   BUILD EXITOSO
echo ========================================
echo   Version : %VERSION%
echo   Package : %BUILD_DIR%\AZCKeeper_v%VERSION%.zip

for %%A in ("%BUILD_DIR%\AZCKeeper_v%VERSION%.zip") do (
    set size=%%~zA
    set /a sizeMB=!size! / 1048576
    set /a sizeKB=!size! / 1024
    echo   Tamano  : !sizeMB! MB (!sizeKB! KB)
    echo   Bytes   : !size!  ^<-- usar este valor en releases.php
)

echo.
echo ========================================
echo   PROXIMOS PASOS
echo ========================================
echo.
echo 1. Sube %BUILD_DIR%\AZCKeeper_v%VERSION%.zip a GitHub Releases
echo    - Tag: v%VERSION%
echo    - Asset name: AZCKeeper_v%VERSION%.zip
echo.
echo 2. En /admin/releases.php crea el release con:
echo    - Version: %VERSION%
echo    - Download URL: https://github.com/.../AZCKeeper_v%VERSION%.zip
echo    - Tamano (bytes): ver arriba
echo    - is_active: SI
echo.
echo 3. En la politica del servidor activa autoDownload=true
echo    para que los clientes descarguen automaticamente.
echo.
echo ========================================

set /p OPEN="Abrir carpeta de salida? (S/N): "
if /i "%OPEN%"=="S" start explorer "%BUILD_DIR%"

pause