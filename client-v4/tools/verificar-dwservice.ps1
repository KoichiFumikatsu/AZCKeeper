<#
  verificar-dwservice.ps1
  Comprueba si el shell desde el que se ejecuta (p. ej. el Shell de DWService)
  puede INSTALAR el agente v4 SIN UAC — es decir, si corre elevado (SYSTEM o admin
  con token completo). No solo reporta membresia: hace pruebas privilegiadas REALES
  (escritura en HKLM + creacion de servicio) y las limpia. Ejecutalo tal cual desde
  el shell de DWService en un equipo de prueba.

  Uso:  powershell -NoProfile -ExecutionPolicy Bypass -File verificar-dwservice.ps1
#>

$ErrorActionPreference = 'SilentlyContinue'
function Line { param($k,$v) "{0,-24} {1}" -f $k, $v }

Write-Output "==================================================================="
Write-Output " AZCKeeper v4 - Verificacion de elevacion del canal (DWService)"
Write-Output "==================================================================="

# --- Identidad ---
$id  = [Security.Principal.WindowsIdentity]::GetCurrent()
$pr  = New-Object Security.Principal.WindowsPrincipal($id)
$isSystem   = $id.IsSystem
$isAdminRole = $pr.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

# Nivel de integridad (Mandatory Label): System > High > Medium
$integrity = 'Desconocido'
$grp = whoami /groups 2>$null
if ($grp -match 'S-1-16-16384|System Mandatory Level')      { $integrity = 'System' }
elseif ($grp -match 'S-1-16-12288|High Mandatory Level')    { $integrity = 'High (elevado)' }
elseif ($grp -match 'S-1-16-8192|Medium Mandatory Level')   { $integrity = 'Medium (NO elevado)' }

Write-Output ""
Write-Output (Line "Usuario:"        $id.Name)
Write-Output (Line "Es SYSTEM:"      $isSystem)
Write-Output (Line "En grupo Admin:" $isAdminRole)
Write-Output (Line "Integridad:"     $integrity)
Write-Output ""
Write-Output "--- Pruebas privilegiadas reales (se limpian solas) ---"

# --- Prueba 1: escribir en HKLM (requiere elevacion) ---
$hklmOk = $false
try {
    $k = 'HKLM:\SOFTWARE\AZCKeeperElevTest'
    New-Item -Path $k -Force -ErrorAction Stop | Out-Null
    New-ItemProperty -Path $k -Name 'probe' -Value 1 -PropertyType DWord -Force -ErrorAction Stop | Out-Null
    Remove-Item -Path $k -Recurse -Force -ErrorAction Stop
    $hklmOk = $true
} catch { $hklmOk = $false }
Write-Output (Line "  Escribir HKLM:" $(if($hklmOk){'OK'}else{'FALLO'}))

# --- Prueba 2: crear+borrar un servicio (lo que hace el instalador real) ---
$svcOk = $false
$svcName = 'AZCKeeperElevTest'
try {
    $c = sc.exe create $svcName binPath= "C:\Windows\System32\cmd.exe /c rem" start= demand 2>&1
    if ($LASTEXITCODE -eq 0) { $svcOk = $true }
} catch { $svcOk = $false }
finally { sc.exe delete $svcName 2>&1 | Out-Null }
Write-Output (Line "  Crear servicio:" $(if($svcOk){'OK'}else{'FALLO'}))

# --- Veredicto ---
Write-Output ""
Write-Output "==================================================================="
if ($hklmOk -and $svcOk) {
    Write-Output " VEREDICTO: APTO. Este shell ejecuta ELEVADO."
    Write-Output " -> DWService sirve para instalar v4 SIN UAC (modo --system-install)."
} elseif ($isSystem -or $integrity -eq 'System' -or $integrity -eq 'High (elevado)') {
    Write-Output " VEREDICTO: PARCIAL. La identidad se ve elevada pero una prueba fallo."
    Write-Output " -> Revisar por que fallo (permisos/politicas). Ver lineas de arriba."
} else {
    Write-Output " VEREDICTO: NO APTO. Token MEDIO (no elevado)."
    Write-Output " -> Desde este shell NO se puede instalar sin UAC. Necesitas el shell"
    Write-Output "    SYSTEM de DWService, o el flujo K3 + UAC como fallback."
}
Write-Output "==================================================================="
