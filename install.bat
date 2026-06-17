@echo off
echo ========================================
echo   AZCKeeper - Instalador v4.0
echo ========================================

:: Detectar usuario con sesion activa (para cuando se ejecuta como SYSTEM/DWService)
set "LOGGED_USER="
for /f "tokens=1" %%u in ('query user 2^>nul ^| findstr /I "Activo Active"') do (
    set "LOGGED_USER=%%u"
)
:: Quitar el > si viene con prefijo
if defined LOGGED_USER set "LOGGED_USER=%LOGGED_USER:>=%"

if defined LOGGED_USER (
    :: Obtener la ruta del perfil del usuario logueado
    for /f "tokens=2,*" %%a in ('reg query "HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList" /s /v ProfileImagePath 2^>nul ^| findstr /I "%LOGGED_USER%"') do (
        set "USER_PROFILE=%%b"
    )
)

if defined USER_PROFILE (
    set "INSTALL_DIR=%USER_PROFILE%\AppData\Local\AZCKeeper\app"
) else (
    set "INSTALL_DIR=%LOCALAPPDATA%\AZCKeeper\app"
)

echo.
echo Instalando en: %INSTALL_DIR%
echo.

:: Paso 1: Cerrar AZCKeeper si esta ejecutandose
echo Verificando procesos activos...
tasklist /FI "IMAGENAME eq AZCKeeper_Client.exe" 2>nul | find /I "AZCKeeper_Client.exe" >nul
if %errorlevel%==0 (
    echo Cerrando AZCKeeper_Client.exe...
    taskkill /F /IM AZCKeeper_Client.exe >nul 2>&1
    timeout /t 2 /nobreak >nul
    echo Proceso cerrado.
) else (
    echo No se encontro AZCKeeper en ejecucion.
)

tasklist /FI "IMAGENAME eq AZCKeeperUpdater.exe" 2>nul | find /I "AZCKeeperUpdater.exe" >nul
if %errorlevel%==0 (
    echo Cerrando AZCKeeperUpdater.exe...
    taskkill /F /IM AZCKeeperUpdater.exe >nul 2>&1
    timeout /t 1 /nobreak >nul
)

:: Paso 2: Limpiar instalaciones residuales en otras rutas (best-effort)
:: Barre binarios viejos, entradas Run/Startup y .lnk de TODAS las rutas
:: conocidas via azc-killer.ps1. NO usa -Purge: conserva %APPDATA%\AZCKeeper
:: (token, cola offline, logs) para no re-enrolar el dispositivo.
:: Si el .ps1 no esta o PowerShell falla, la instalacion continua igual.
if exist "%~dp0azc-killer.ps1" (
    echo Limpiando instalaciones residuales...
    powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0azc-killer.ps1" -KillProcs -RemoveApp -RemoveRegistry
    echo Limpieza de residuales completada.
) else (
    echo azc-killer.ps1 no encontrado; se omite limpieza profunda.
)

:: Paso 3: Limpiar instalacion anterior del usuario activo (baseline garantizado)
if exist "%INSTALL_DIR%" (
    echo Eliminando version anterior...
    rmdir /S /Q "%INSTALL_DIR%"
    timeout /t 1 /nobreak >nul
)

:: Paso 4: Crear directorio e instalar
mkdir "%INSTALL_DIR%"

echo Copiando archivos nuevos...
xcopy /Y /E /I "%~dp0*.*" "%INSTALL_DIR%"

:: Quitar de la carpeta de la app los scripts de instalacion (stealth):
:: no deben quedar visibles junto al cliente en ejecucion.
del /F /Q "%INSTALL_DIR%\azc-killer.ps1" >nul 2>&1
del /F /Q "%INSTALL_DIR%\install.bat" >nul 2>&1

echo.
echo ========================================
echo   Instalacion completada.
echo ========================================
echo.
echo Iniciando AZCKeeper...
start "" "%INSTALL_DIR%\AZCKeeper_Client.exe"

echo.
echo Presiona cualquier tecla para salir...
pause >nul