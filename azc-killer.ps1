# azc-killer.ps1
# Detecta + reporta + opcionalmente elimina instalaciones residuales de AZCKeeper en Windows.
#
# Uso:
#   .\azc-killer.ps1                       -> DryRun (default): solo reporta
#   .\azc-killer.ps1 -KillProcs            -> mata procesos AZCKeeper*
#   .\azc-killer.ps1 -KillProcs -RemoveApp -> + borra carpetas de aplicacion (binarios)
#   .\azc-killer.ps1 -Purge                -> + borra config/logs/queue (%APPDATA%\AZCKeeper completo)
#   .\azc-killer.ps1 -RemoveRegistry       -> borra entradas Run HKCU/HKLM
#   .\azc-killer.ps1 -DeepScan             -> escaneo profundo en C: y D: (lento)

[CmdletBinding()]
param(
    [switch]$KillProcs,
    [switch]$RemoveApp,
    [switch]$Purge,
    [switch]$RemoveRegistry,
    [switch]$DeepScan
)

$ErrorActionPreference = 'Continue'

function Write-Section($title) {
    Write-Host ""
    Write-Host "=== $title ===" -ForegroundColor Cyan
}

# ---------- 1. Procesos ----------
Write-Section "Procesos AZCKeeper*"
$procs = Get-Process | Where-Object { $_.Name -like 'AZCKeeper*' }
if ($procs) {
    $procs | Select-Object Name, Id, Path | Format-Table -AutoSize | Out-String | Write-Host
    if ($KillProcs) {
        foreach ($p in $procs) {
            try {
                Stop-Process -Id $p.Id -Force -ErrorAction Stop
                Write-Host "  [killed] $($p.Name) (PID $($p.Id))" -ForegroundColor Green
            } catch {
                Write-Host "  [FAIL]  $($p.Name) (PID $($p.Id)): $($_.Exception.Message)" -ForegroundColor Red
            }
        }
    } else {
        Write-Host "  (dry-run; pasa -KillProcs para matar)" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ninguno" -ForegroundColor Gray
}

# ---------- 2. Carpetas conocidas ----------
Write-Section "Carpetas AZCKeeper conocidas"

$appPaths = @(
    "$env:LOCALAPPDATA\AZCKeeper",
    "C:\Program Files\AZCKeeper",
    "C:\Program Files (x86)\AZCKeeper",
    "C:\xampp\htdocs\AZCKeeper",
    "C:\proyectos\AZCKeeper",
    "C:\AZCKeeper"
)
$dataPaths = @(
    "$env:APPDATA\AZCKeeper"
)

# Tambien revisar otros perfiles de usuario
try {
    $userProfiles = Get-ChildItem 'C:\Users' -Directory -ErrorAction Stop | Where-Object { $_.Name -notin @('Public','Default','Default User','All Users') }
    foreach ($u in $userProfiles) {
        $appPaths += "$($u.FullName)\AppData\Local\AZCKeeper"
        $dataPaths += "$($u.FullName)\AppData\Roaming\AZCKeeper"
    }
} catch { }

$foundApp  = @()
$foundData = @()

foreach ($p in ($appPaths | Select-Object -Unique)) {
    if (Test-Path $p) {
        $size = (Get-ChildItem $p -Recurse -ErrorAction SilentlyContinue | Measure-Object Length -Sum).Sum
        $sizeMB = if ($size) { [math]::Round($size/1MB,1) } else { 0 }
        Write-Host "  [APP]  $p  ($sizeMB MB)" -ForegroundColor Yellow
        $foundApp += $p
    }
}
foreach ($p in ($dataPaths | Select-Object -Unique)) {
    if (Test-Path $p) {
        $size = (Get-ChildItem $p -Recurse -ErrorAction SilentlyContinue | Measure-Object Length -Sum).Sum
        $sizeMB = if ($size) { [math]::Round($size/1MB,1) } else { 0 }
        Write-Host "  [DATA] $p  ($sizeMB MB)" -ForegroundColor Magenta
        $foundData += $p
    }
}
if (-not $foundApp -and -not $foundData) {
    Write-Host "  ninguna" -ForegroundColor Gray
}

if ($RemoveApp -and $foundApp) {
    Write-Host ""
    Write-Host "Borrando carpetas APP..." -ForegroundColor Yellow
    foreach ($p in $foundApp) {
        try {
            Remove-Item -Path $p -Recurse -Force -ErrorAction Stop
            Write-Host "  [removed] $p" -ForegroundColor Green
        } catch {
            Write-Host "  [FAIL]    $p : $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} elseif ($foundApp -and -not $RemoveApp) {
    Write-Host "  (dry-run APP; pasa -RemoveApp para borrar binarios)" -ForegroundColor Yellow
}

if ($Purge -and $foundData) {
    Write-Host ""
    Write-Host "PURGE: borrando carpetas DATA (config/logs/queue/token)..." -ForegroundColor Red
    foreach ($p in $foundData) {
        try {
            Remove-Item -Path $p -Recurse -Force -ErrorAction Stop
            Write-Host "  [purged]  $p" -ForegroundColor Green
        } catch {
            Write-Host "  [FAIL]    $p : $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} elseif ($foundData -and -not $Purge) {
    Write-Host "  (dry-run DATA; pasa -Purge para borrar config/logs/queue)" -ForegroundColor Yellow
}

# ---------- 3. Registry Run ----------
Write-Section "Entradas Run en registry"
$runKeys = @(
    "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run",
    "HKLM:\Software\Microsoft\Windows\CurrentVersion\Run"
)
$regHits = @()
foreach ($k in $runKeys) {
    try {
        $props = Get-ItemProperty -Path $k -ErrorAction Stop
        $props.PSObject.Properties | Where-Object { $_.Name -like '*AZCKeeper*' -or $_.Value -like '*AZCKeeper*' } | ForEach-Object {
            Write-Host "  [$k]  $($_.Name) = $($_.Value)" -ForegroundColor Yellow
            $regHits += [pscustomobject]@{ Key = $k; Name = $_.Name }
        }
    } catch { }
}
if (-not $regHits) {
    Write-Host "  ninguna" -ForegroundColor Gray
}

if ($RemoveRegistry -and $regHits) {
    foreach ($h in $regHits) {
        try {
            Remove-ItemProperty -Path $h.Key -Name $h.Name -ErrorAction Stop
            Write-Host "  [removed] $($h.Key)\$($h.Name)" -ForegroundColor Green
        } catch {
            Write-Host "  [FAIL]    $($h.Key)\$($h.Name): $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} elseif ($regHits -and -not $RemoveRegistry) {
    Write-Host "  (dry-run REG; pasa -RemoveRegistry para borrar)" -ForegroundColor Yellow
}

# ---------- 4. Startup folders (.lnk) ----------
Write-Section "Carpetas Startup (.lnk per-user y all-users)"
$startupFolders = @(
    [Environment]::GetFolderPath('Startup'),
    [Environment]::GetFolderPath('CommonStartup')
)
try {
    $userProfiles2 = Get-ChildItem 'C:\Users' -Directory -ErrorAction Stop | Where-Object { $_.Name -notin @('Public','Default','Default User','All Users') }
    foreach ($u in $userProfiles2) {
        $startupFolders += "$($u.FullName)\AppData\Roaming\Microsoft\Windows\Start Menu\Programs\Startup"
    }
} catch { }

$lnkHits = @()
foreach ($sf in ($startupFolders | Select-Object -Unique)) {
    if (-not (Test-Path $sf)) { continue }
    $shell = New-Object -ComObject WScript.Shell
    Get-ChildItem -Path $sf -Filter '*.lnk' -ErrorAction SilentlyContinue | ForEach-Object {
        $target = ''
        try { $target = $shell.CreateShortcut($_.FullName).TargetPath } catch { }
        if ($_.Name -like '*AZCKeeper*' -or $target -like '*AZCKeeper*') {
            Write-Host "  [LNK] $($_.FullName)" -ForegroundColor Yellow
            Write-Host "        -> $target" -ForegroundColor Gray
            $lnkHits += $_.FullName
        }
    }
}
if (-not $lnkHits) {
    Write-Host "  ninguno" -ForegroundColor Gray
}
if ($RemoveRegistry -and $lnkHits) {
    foreach ($l in $lnkHits) {
        try {
            Remove-Item -Path $l -Force -ErrorAction Stop
            Write-Host "  [removed] $l" -ForegroundColor Green
        } catch {
            Write-Host "  [FAIL]    $l : $($_.Exception.Message)" -ForegroundColor Red
        }
    }
} elseif ($lnkHits -and -not $RemoveRegistry) {
    Write-Host "  (dry-run LNK; pasa -RemoveRegistry para borrar accesos directos)" -ForegroundColor Yellow
}

# ---------- 5. Scheduled Tasks ----------
Write-Section "Tareas programadas"
try {
    $tasks = Get-ScheduledTask -ErrorAction SilentlyContinue | Where-Object { $_.TaskName -like '*AZCKeeper*' -or $_.Actions.Execute -like '*AZCKeeper*' }
    if ($tasks) {
        $tasks | Select-Object TaskName, State, TaskPath | Format-Table -AutoSize | Out-String | Write-Host
    } else {
        Write-Host "  ninguna" -ForegroundColor Gray
    }
} catch {
    Write-Host "  (sin permisos para enumerar)" -ForegroundColor Gray
}

# ---------- 5. Deep scan opcional ----------
if ($DeepScan) {
    Write-Section "DeepScan: buscando AZCKeeper* en C:\ y D:\ (lento)"
    foreach ($root in @('C:\','D:\')) {
        if (Test-Path $root) {
            Get-ChildItem -Path $root -Filter 'AZCKeeper*' -Recurse -ErrorAction SilentlyContinue -Force |
                Where-Object { $_.PSIsContainer -or $_.Extension -in '.exe','.dll','.bat','.json' } |
                Select-Object FullName, Length, LastWriteTime |
                Format-Table -AutoSize | Out-String | Write-Host
        }
    }
}

# ---------- 6. Resumen ----------
Write-Section "Resumen"
Write-Host "  Procesos vivos    : $(if ($procs) { $procs.Count } else { 0 })"
Write-Host "  Carpetas APP      : $($foundApp.Count)"
Write-Host "  Carpetas DATA     : $($foundData.Count)"
Write-Host "  Entradas registry : $($regHits.Count)"
Write-Host "  Accesos Startup   : $($lnkHits.Count)"

if (-not ($KillProcs -or $RemoveApp -or $Purge -or $RemoveRegistry)) {
    Write-Host ""
    Write-Host "Para limpiar (recomendado):" -ForegroundColor Cyan
    Write-Host "  .\azc-killer.ps1 -KillProcs -RemoveApp -RemoveRegistry" -ForegroundColor White
    Write-Host "Para reset completo (incluye token y logs):" -ForegroundColor Cyan
    Write-Host "  .\azc-killer.ps1 -KillProcs -RemoveApp -Purge -RemoveRegistry" -ForegroundColor White
}
