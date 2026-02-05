@echo off
setlocal enabledelayedexpansion
 
echo ========================================
echo   AZCKeeper Build Script v1.3
echo   Self-Contained + Optimizado
echo ========================================
 
set VERSION=3.0.0.0
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
  -o "%BUILD_DIR%\updater"
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
  -o "%BUILD_DIR%\package"
if errorlevel 1 (
    echo ERROR: Fallo al compilar Cliente
    pause
    exit /b 1
)
cd ..
 
echo.
echo [4/5] Copiando Updater al package...
copy /Y "%BUILD_DIR%\updater\AZCKeeperUpdater.exe" "%BUILD_DIR%\package\"
 
echo.
echo [5/5] Creando ZIP de distribución...
powershell -Command "Compress-Archive -Path '%BUILD_DIR%\package\*' -DestinationPath '%BUILD_DIR%\AZCKeeper_v%VERSION%.zip' -Force"
 
echo.
echo ========================================
echo   BUILD EXITOSO
echo ========================================
echo   Package: %BUILD_DIR%\AZCKeeper_v%VERSION%.zip
 
for %%A in ("%BUILD_DIR%\AZCKeeper_v%VERSION%.zip") do (
    set size=%%~zA
    set /a sizeMB=!size! / 1048576
    echo   Tamaño: !sizeMB! MB
)
 
echo.
echo   Contenido del package:
dir "%BUILD_DIR%\package" /b | findstr /v "*.pdb"
echo.
echo   ¿Quieres abrir la carpeta? (S/N)
set /p OPEN=
if /i "%OPEN%"=="S" start explorer "%BUILD_DIR%\package"
echo ========================================
echo.
echo ========================================
echo   INSTRUCCIONES DE INSTALACIÓN
echo ========================================
echo.
echo 1. Extrae el contenido de AZCKeeper_v%VERSION%.zip en:
echo    %%LocalAppData%%\AZCKeeper\app\
echo.
echo 2. O copia y pega esta ruta en el Explorador:
echo    %%LOCALAPPDATA%%\AZCKeeper\app
echo.
echo 3. Ejecuta AZCKeeper_Client.exe desde esa ubicación
echo.
echo 4. El programa se configurará automáticamente en el inicio
echo.
echo ========================================
pause