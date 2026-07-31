# azc-unblock.ps1
# Limpia TODAS las capas de bloqueo web que AZCKeeper 3.x llego a usar en un equipo,
# incluidas las de versiones viejas que quedan "arraigadas" cuando el cliente ya no
# las administra. Reporta primero; solo cambia algo con -Apply.
#
# Capas que cubre (por orden historico):
#   1. PAC per-usuario   HKCU ...\Internet Settings\AutoConfigURL -> http://127.0.0.1:<port>/proxy.pac   (3.0.2.7+)
#   2. Proxy estatico    HKCU/HKLM ...\Internet Settings\ProxyEnable + ProxyServer = 127.0.0.1:<port>    (<=3.0.2.4)
#   3. URLBlocklist      HKCU/HKLM SOFTWARE\Policies\{Chrome,Edge,Brave}\URLBlocklist                    (3.0.2.5/2.6)
#   4. hosts file        bloque entre "# AZCKeeper WEB BLOCK BEGIN/END"                                  (<=3.0.2.4)
#   +  cache local       %APPDATA%\AZCKeeper\Cache\{web_block_cache.json,system_proxy_backup.json,pac_port.txt}
#   +  WinHTTP y cache DNS
#
# CLAVE: el cliente reaplica el PAC en CADA handshake (Reassert) y al arrancar aplica
# web_block_cache.json aunque el servidor no responda. Por eso este script mata el
# proceso ANTES de limpiar y borra la cache. Si no, el bloqueo vuelve en <=5 min.
#
# Uso:
#   .\azc-unblock.ps1                          -> DIAGNOSTICO (no toca nada)
#   .\azc-unblock.ps1 -Apply                   -> limpia lo que es nuestro (127.0.0.1)
#   .\azc-unblock.ps1 -Apply -Force            -> + quita proxy/PAC ajenos y politicas HKLM
#   .\azc-unblock.ps1 -Apply -RestartClient    -> + relanza el cliente al terminar
#
# Correr en PowerShell COMO ADMINISTRADOR para cubrir HKLM, hosts, WinHTTP y todos los
# perfiles del equipo. Sin admin limpia solo el usuario actual (suficiente para el PAC).

[CmdletBinding()]
param(
    [switch]$Apply,
    [switch]$Force,
    [switch]$RestartClient,
    [switch]$KeepCache
)

$ErrorActionPreference = 'Continue'

function Write-Section($t) { Write-Host ""; Write-Host "=== $t ===" -ForegroundColor Cyan }
function Say-Ok($m)   { Write-Host "  [ok]    $m" -ForegroundColor Green }
function Say-Hit($m)  { Write-Host "  [hit]   $m" -ForegroundColor Yellow }
function Say-Info($m) { Write-Host "  $m" -ForegroundColor Gray }
function Say-Err($m)  { Write-Host "  [FAIL]  $m" -ForegroundColor Red }
function Would($m)    { if ($Apply) { Write-Host "  [fix]   $m" -ForegroundColor Green } else { Write-Host "  [dry]   $m" -ForegroundColor Yellow } }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
$script:hits = 0

Write-Host "azc-unblock  -  modo: $(if ($Apply) { 'APLICAR' } else { 'diagnostico (dry-run)' })  |  admin: $isAdmin" -ForegroundColor White
if (-not $isAdmin) { Write-Host "  (sin admin: no se tocan HKLM, hosts, WinHTTP ni otros perfiles)" -ForegroundColor DarkYellow }

# ---------- 0. Parar el enforcer ----------
# Obligatorio ANTES de limpiar: si el cliente sigue vivo, Reassert() vuelve a poner el PAC.
Write-Section "0. Procesos AZCKeeper (el que reaplica el bloqueo)"
$clientExe = $null
$procs = Get-Process | Where-Object { $_.Name -like 'AZCKeeper*' }
if ($procs) {
    foreach ($p in $procs) {
        Say-Hit "$($p.Name) PID $($p.Id)  $($p.Path)"
        if ($p.Name -eq 'AZCKeeper_Client' -and $p.Path) { $clientExe = $p.Path }
    }
    if ($Apply) {
        foreach ($p in $procs) {
            try { Stop-Process -Id $p.Id -Force -ErrorAction Stop; Say-Ok "detenido $($p.Name)" }
            catch { Say-Err "no se pudo detener $($p.Name): $($_.Exception.Message)" }
        }
        Start-Sleep -Milliseconds 800
    } else { Would "detener estos procesos" }
} else { Say-Info "ninguno corriendo" }

# ---------- Perfiles objetivo ----------
# El PAC vive en HKCU: si esto corre como SYSTEM (DWService) el HKCU es el de SYSTEM,
# no el del empleado. Por eso se recorre HKEY_USERS y se resuelve el perfil de cada SID.
$targets = @()
$mySid = ([Security.Principal.WindowsIdentity]::GetCurrent()).User.Value
$targets += [pscustomobject]@{ Sid = $mySid; Hive = "Registry::HKEY_USERS\$mySid"; Profile = $env:USERPROFILE; Label = "$env:USERNAME (actual)" }

if ($isAdmin) {
    Get-ChildItem 'Registry::HKEY_USERS' -ErrorAction SilentlyContinue | ForEach-Object {
        $sid = $_.PSChildName
        if ($sid -match '^S-1-5-21-[\d-]+$' -and $sid -ne $mySid) {
            $pp = $null
            try { $pp = (Get-ItemProperty "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid" -Name ProfileImagePath -ErrorAction Stop).ProfileImagePath } catch { }
            $targets += [pscustomobject]@{ Sid = $sid; Hive = "Registry::HKEY_USERS\$sid"; Profile = $pp; Label = $(if ($pp) { Split-Path $pp -Leaf } else { $sid }) }
        }
    }
}
Write-Section "Perfiles a limpiar"
foreach ($t in $targets) { Say-Info "$($t.Label)  ->  $($t.Sid)" }
if ($isAdmin) { Say-Info "(solo aparecen los perfiles con la sesion cargada; un usuario que nunca inicio sesion no tiene hive montado)" }

# ---------- 1-3. Por usuario: PAC, proxy estatico, URLBlocklist, cache ----------
foreach ($t in $targets) {
    Write-Section "Usuario $($t.Label)"
    $IS   = "$($t.Hive)\Software\Microsoft\Windows\CurrentVersion\Internet Settings"
    $CONN = "$IS\Connections"
    $touched = $false

    if (-not (Test-Path $IS)) { Say-Info "sin Internet Settings"; continue }

    $acu   = (Get-ItemProperty -Path $IS -Name 'AutoConfigURL' -ErrorAction SilentlyContinue).AutoConfigURL
    $pEn   = (Get-ItemProperty -Path $IS -Name 'ProxyEnable'   -ErrorAction SilentlyContinue).ProxyEnable
    $pSrv  = (Get-ItemProperty -Path $IS -Name 'ProxyServer'   -ErrorAction SilentlyContinue).ProxyServer
    $pOvr  = (Get-ItemProperty -Path $IS -Name 'ProxyOverride' -ErrorAction SilentlyContinue).ProxyOverride

    Say-Info "AutoConfigURL = $(if ($acu) { $acu } else { '(vacio)' })"
    Say-Info "ProxyEnable   = $(if ($null -ne $pEn) { $pEn } else { '(vacio)' })   ProxyServer = $(if ($pSrv) { $pSrv } else { '(vacio)' })   ProxyOverride = $(if ($pOvr) { $pOvr } else { '(vacio)' })"

    # --- PAC (capa 1) ---
    if ($acu) {
        $ours = $acu -match '127\.0\.0\.1|localhost'
        if ($ours -or $Force) {
            $script:hits++
            Say-Hit "PAC de AZCKeeper activo$(if (-not $ours) { ' (ajeno, se quita por -Force)' })"
            Would "borrar AutoConfigURL"
            if ($Apply) {
                try { Remove-ItemProperty -Path $IS -Name 'AutoConfigURL' -Force -ErrorAction Stop; $touched = $true } catch { Say-Err $_.Exception.Message }
            }
        } else {
            Say-Hit "AutoConfigURL AJENO (no es nuestro loopback) - no se toca. Usa -Force si tambien sobra."
        }
    }

    # --- Proxy estatico a loopback (capa 2) ---
    if ($pSrv -and ($pSrv -match '127\.0\.0\.1|localhost' -or $Force)) {
        $script:hits++
        Say-Hit "proxy estatico $pSrv"
        Would "ProxyEnable=0 y ProxyServer vacio"
        if ($Apply) {
            try {
                Set-ItemProperty -Path $IS -Name 'ProxyEnable' -Value 0 -Type DWord -Force -ErrorAction Stop
                Set-ItemProperty -Path $IS -Name 'ProxyServer' -Value '' -Type String -Force -ErrorAction Stop
                $touched = $true
            } catch { Say-Err $_.Exception.Message }
        }
    } elseif ($pEn -eq 1) {
        Say-Hit "ProxyEnable=1 con proxy ajeno ($pSrv) - no se toca (posible proxy corporativo)."
    }

    # --- Blob binario de conexiones ---
    # WinInet cachea la config por conexion aqui; si queda el blob viejo puede resucitar
    # el PAC aunque las cadenas ya esten limpias. Windows lo reconstruye solo.
    if ($touched -and (Test-Path $CONN)) {
        foreach ($v in @('DefaultConnectionSettings','SavedLegacySettings')) {
            if ($null -ne (Get-ItemProperty -Path $CONN -Name $v -ErrorAction SilentlyContinue)) {
                Would "borrar blob $v (Windows lo regenera)"
                if ($Apply) { try { Remove-ItemProperty -Path $CONN -Name $v -Force -ErrorAction Stop } catch { Say-Err $_.Exception.Message } }
            }
        }
    }

    # --- URLBlocklist per-usuario (capa 3) ---
    foreach ($pol in @('Google\Chrome','Microsoft\Edge','BraveSoftware\Brave')) {
        $k = "$($t.Hive)\SOFTWARE\Policies\$pol\URLBlocklist"
        if (Test-Path $k) {
            $script:hits++
            $n = (Get-Item $k).ValueCount
            Say-Hit "URLBlocklist HKU\$pol ($n dominios)"
            Would "borrar la clave completa"
            if ($Apply) { try { Remove-Item -Path $k -Recurse -Force -ErrorAction Stop } catch { Say-Err $_.Exception.Message } }
        }
    }

    # --- Cache local: la razon #1 de que el bloqueo quede "arraigado" ---
    # Al arrancar, el cliente aplica web_block_cache.json ANTES de hablar con el server;
    # si el equipo no logra handshake, el bloqueo sobrevive indefinidamente.
    if ($t.Profile) {
        $cacheDir = Join-Path $t.Profile 'AppData\Roaming\AZCKeeper\Cache'
        $bak = Join-Path $cacheDir 'system_proxy_backup.json'
        if (Test-Path $bak) {
            Say-Info "system_proxy_backup.json (lo que el cliente restauraria):"
            try { (Get-Content $bak -Raw).Trim() -split "`n" | ForEach-Object { Write-Host "      $_" -ForegroundColor DarkGray } } catch { }
        }
        foreach ($f in @('web_block_cache.json','system_proxy_backup.json','pac_port.txt')) {
            $p = Join-Path $cacheDir $f
            if (Test-Path $p) {
                $script:hits++
                Say-Hit "cache $f"
                if ($KeepCache) { Say-Info "(-KeepCache: se conserva)" }
                else {
                    Would "borrar $p"
                    if ($Apply) { try { Remove-Item $p -Force -ErrorAction Stop } catch { Say-Err $_.Exception.Message } }
                }
            }
        }
    }
}

# ---------- 4. HKLM: proxy de maquina y politicas de navegador ----------
Write-Section "4. HKLM (maquina)"
if ($isAdmin) {
    $hklmHits = 0
    $ISM = 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Internet Settings'
    $acuM  = (Get-ItemProperty -Path $ISM -Name 'AutoConfigURL' -ErrorAction SilentlyContinue).AutoConfigURL
    $pSrvM = (Get-ItemProperty -Path $ISM -Name 'ProxyServer'   -ErrorAction SilentlyContinue).ProxyServer
    if ($acuM -and ($acuM -match '127\.0\.0\.1|localhost' -or $Force)) {
        $script:hits++; $hklmHits++; Say-Hit "AutoConfigURL HKLM = $acuM"; Would "borrarlo"
        if ($Apply) { try { Remove-ItemProperty -Path $ISM -Name 'AutoConfigURL' -Force -ErrorAction Stop } catch { Say-Err $_.Exception.Message } }
    }
    if ($pSrvM -and ($pSrvM -match '127\.0\.0\.1|localhost' -or $Force)) {
        $script:hits++; $hklmHits++; Say-Hit "ProxyServer HKLM = $pSrvM"; Would "ProxyEnable=0 + ProxyServer vacio"
        if ($Apply) {
            try {
                Set-ItemProperty -Path $ISM -Name 'ProxyEnable' -Value 0 -Type DWord -Force -ErrorAction Stop
                Set-ItemProperty -Path $ISM -Name 'ProxyServer' -Value '' -Type String -Force -ErrorAction Stop
            } catch { Say-Err $_.Exception.Message }
        }
    }
    # Politicas de navegador en HKLM: K3 NUNCA las escribe (eso es K4/GPO/Intune).
    # Por eso solo se REPORTAN; borrarlas exige -Force explicito.
    foreach ($pol in @('Google\Chrome','Microsoft\Edge','BraveSoftware\Brave')) {
        foreach ($sub in @('URLBlocklist','ExtensionInstallBlocklist')) {
            foreach ($base in @("HKLM:\SOFTWARE\Policies\$pol", "HKLM:\SOFTWARE\WOW6432Node\Policies\$pol")) {
                $k = "$base\$sub"
                if (Test-Path $k) {
                    $script:hits++; $hklmHits++
                    Say-Hit "$k ($((Get-Item $k).ValueCount) entradas) - NO es de Keeper 3 (GPO/Intune/K4)"
                    if ($Force) {
                        Would "borrar la clave (-Force)"
                        if ($Apply) { try { Remove-Item -Path $k -Recurse -Force -ErrorAction Stop } catch { Say-Err $_.Exception.Message } }
                    } else { Say-Info "(usa -Force si confirmas que debe salir)" }
                }
            }
        }
    }
    if ($hklmHits -eq 0) { Say-Info "nada en HKLM" }
} else { Say-Info "omitido (requiere admin)" }

# ---------- 5. hosts ----------
Write-Section "5. hosts file"
$hosts = "$env:SystemRoot\System32\drivers\etc\hosts"
$hasBlock = $false
try { $hasBlock = (Select-String -Path $hosts -Pattern 'AZCKeeper WEB BLOCK BEGIN' -SimpleMatch -Quiet) } catch { }
if ($hasBlock) {
    $script:hits++
    Say-Hit "bloque '# AZCKeeper WEB BLOCK' presente en hosts"
    if (-not $isAdmin) { Say-Info "(requiere admin para quitarlo)" }
    else {
        Would "quitar solo ese bloque (respaldo en hosts.azcbak)"
        if ($Apply) {
            try {
                Copy-Item $hosts "$hosts.azcbak" -Force
                $raw = Get-Content $hosts -Raw
                $clean = [regex]::Replace($raw, '(?s)\r?\n?#\s*AZCKeeper WEB BLOCK BEGIN.*?#\s*AZCKeeper WEB BLOCK END\r?\n?', "`r`n")
                Set-Content -Path $hosts -Value $clean -Encoding ASCII -Force
                Say-Ok "bloque removido (respaldo: $hosts.azcbak)"
            } catch { Say-Err $_.Exception.Message }
        }
    }
} else { Say-Info "limpio" }

# ---------- 6. WinHTTP + DNS + avisar a WinInet ----------
Write-Section "6. WinHTTP / DNS / refresco"
if ($isAdmin) {
    $wh = (netsh winhttp show proxy) -join ' '
    Say-Info "winhttp: $($wh.Trim())"
    if ($wh -match '127\.0\.0\.1|Servidor proxy|Proxy Server') {
        Would "netsh winhttp reset proxy"
        if ($Apply) { netsh winhttp reset proxy | Out-Null; Say-Ok "winhttp reseteado" }
    }
} else { Say-Info "winhttp omitido (requiere admin)" }

if ($Apply) {
    ipconfig /flushdns | Out-Null
    Say-Ok "cache DNS vaciada"
    # Avisa a WinInet para que navegadores/Office ya abiertos relean la config sin reiniciar.
    try {
        if (-not ('Azc.WinInet' -as [type])) {
            Add-Type -Namespace Azc -Name WinInet -MemberDefinition @'
[System.Runtime.InteropServices.DllImport("wininet.dll", SetLastError=true)]
public static extern bool InternetSetOption(System.IntPtr h, int opt, System.IntPtr buf, int len);
'@ -ErrorAction SilentlyContinue
        }
        [Azc.WinInet]::InternetSetOption([IntPtr]::Zero, 39, [IntPtr]::Zero, 0) | Out-Null  # SETTINGS_CHANGED
        [Azc.WinInet]::InternetSetOption([IntPtr]::Zero, 37, [IntPtr]::Zero, 0) | Out-Null  # REFRESH
        Say-Ok "WinInet notificado (no hace falta reiniciar Windows)"
    } catch { Say-Info "no se pudo notificar a WinInet: reinicia el navegador" }
}

# ---------- 7. Verificacion ----------
Write-Section "7. Estado final"
foreach ($t in $targets) {
    $IS = "$($t.Hive)\Software\Microsoft\Windows\CurrentVersion\Internet Settings"
    if (-not (Test-Path $IS)) { continue }
    $a = (Get-ItemProperty -Path $IS -Name 'AutoConfigURL' -ErrorAction SilentlyContinue).AutoConfigURL
    $e = (Get-ItemProperty -Path $IS -Name 'ProxyEnable'   -ErrorAction SilentlyContinue).ProxyEnable
    $s = (Get-ItemProperty -Path $IS -Name 'ProxyServer'   -ErrorAction SilentlyContinue).ProxyServer
    $ok = (-not $a) -and ($e -ne 1 -or -not $s)
    Write-Host "  $($t.Label): AutoConfigURL=$(if ($a) { $a } else { '(vacio)' })  ProxyEnable=$(if ($null -ne $e) { $e } else { 0 })  ProxyServer=$(if ($s) { $s } else { '(vacio)' })" -ForegroundColor $(if ($ok) { 'Green' } else { 'Yellow' })
}

# ---------- 8. Relanzar cliente ----------
if ($RestartClient -and $Apply) {
    Write-Section "8. Relanzar cliente"
    if (-not $clientExe) {
        foreach ($c in @("$env:LOCALAPPDATA\AZCKeeper\app\AZCKeeper_Client.exe", "$env:APPDATA\AZCKeeper\app\AZCKeeper_Client.exe")) {
            if (Test-Path $c) { $clientExe = $c; break }
        }
    }
    if ($clientExe) {
        try { Start-Process $clientExe -WorkingDirectory (Split-Path $clientExe); Say-Ok "relanzado $clientExe" }
        catch { Say-Err $_.Exception.Message }
    } else { Say-Info "no se encontro AZCKeeper_Client.exe (arranca solo al iniciar sesion)" }
}

# ---------- Cierre ----------
Write-Section "Resumen"
Write-Host "  Hallazgos: $script:hits"
if (-not $Apply -and $script:hits -gt 0) {
    Write-Host ""
    Write-Host "  Para limpiar:  .\azc-unblock.ps1 -Apply" -ForegroundColor White
}
Write-Host ""
Write-Host "  OJO: esto limpia el EQUIPO, no la politica del servidor." -ForegroundColor Yellow
Write-Host "  Si en /admin/policies.php el bloqueo sigue activo para ese usuario, el cliente" -ForegroundColor Yellow
Write-Host "  lo vuelve a aplicar en el siguiente handshake (<=5 min)." -ForegroundColor Yellow
Write-Host "  Revisa TAMBIEN las politicas de scope 'user' (prioridad 50): GANAN sobre la global (prioridad 1)." -ForegroundColor Yellow
