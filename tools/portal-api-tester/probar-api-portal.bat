@echo off
rem Probador local de la API del Portal de Keeper: http://127.0.0.1:8790
cd /d "%~dp0"
set PHP=
for /d %%d in (C:\laragon\bin\php\php-8.3*) do set PHP=%%d\php.exe
if not defined PHP (echo No encontre PHP en C:\laragon\bin\php. & pause & exit /b 1)
start "" "http://127.0.0.1:8790/"
echo Probador corriendo en http://127.0.0.1:8790  (cierra esta ventana para detenerlo)
"%PHP%" -S 127.0.0.1:8790 -t .
