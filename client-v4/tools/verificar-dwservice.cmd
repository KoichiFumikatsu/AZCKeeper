@echo off
rem verificar-dwservice.cmd
rem Comprueba si este shell (p.ej. el Shell de DWService) ejecuta ELEVADO
rem (SYSTEM/admin token completo) haciendo pruebas privilegiadas reales que se
rem limpian solas. Ejecutalo tal cual desde el shell de DWService.

echo ===================================================================
echo  AZCKeeper v4 - Verificacion de elevacion del canal (DWService)
echo ===================================================================
echo.
echo Usuario:
whoami
echo.
echo Integridad (Mandatory Level):
whoami /groups | findstr /C:"Mandatory Level"
echo.
echo --- Pruebas privilegiadas reales (se limpian solas) ---

set "HKLMRES=FALLO"
reg add "HKLM\SOFTWARE\AZCKeeperElevTest" /v probe /t REG_DWORD /d 1 /f >nul 2>&1
if not errorlevel 1 (
  set "HKLMRES=OK"
  reg delete "HKLM\SOFTWARE\AZCKeeperElevTest" /f >nul 2>&1
)
echo   Escribir HKLM:  %HKLMRES%

set "SVCRES=FALLO"
sc create AZCKeeperElevTest binPath= "C:\Windows\System32\cmd.exe /c rem" start= demand >nul 2>&1
if not errorlevel 1 (
  set "SVCRES=OK"
  sc delete AZCKeeperElevTest >nul 2>&1
)
echo   Crear servicio: %SVCRES%
echo.
echo ===================================================================
if "%HKLMRES%"=="OK" if "%SVCRES%"=="OK" (
  echo  VEREDICTO: APTO. Este shell ejecuta ELEVADO.
  echo  -^> DWService sirve para instalar v4 SIN UAC ^(modo --system-install^).
  goto :fin
)
echo  VEREDICTO: NO APTO o PARCIAL. Token medio o una prueba fallo.
echo  -^> Necesitas el shell SYSTEM de DWService, o K3 + UAC como fallback.
:fin
echo ===================================================================
