@echo off
echo ========================================
echo   AZCKeeper - Instalador v3.0
echo ========================================
 
set INSTALL_DIR=%LOCALAPPDATA%\AZCKeeper\app
 
echo.
echo Instalando en: %INSTALL_DIR%
echo.
 
if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%"
 
echo Copiando archivos...
xcopy /Y /E /I "%~dp0*.*" "%INSTALL_DIR%"
 
echo.
echo Instalacion completada.
echo.
echo Iniciando AZCKeeper...
start "" "%INSTALL_DIR%\AZCKeeper_Client.exe"
 
echo.
echo Presiona cualquier tecla para salir...
pause >nul