# build-release.ps1 — Empaqueta el cliente Keeper 4 autocontenido (runtime .NET embebido).
#
# Produce en build\:
#   AZCKeeper4-Setup.exe   -> Setup de UN SOLO archivo (self-contained, self-instalable,
#                             con el updater embebido). Correrlo instala per-user, sin admin.
#   AZCKeeper4-update.zip  -> lo que descarga el auto-updater: AZCKeeper4.exe + AZCKeeperUpdater.exe.
#
# Orden IMPORTANTE: primero se publica el updater; el csproj del cliente lo embebe y lo
# copia al lado SOLO si ya existe publicado.
#
# Uso:  powershell -ExecutionPolicy Bypass -File build-release.ps1 [-Version 4.0.0.0]

param(
    [string]$Version = "4.0.0.0",
    [string]$Rid = "win-x64"
)

$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$build = Join-Path $root "build"

Write-Host "== Keeper 4 build-release  (version $Version, $Rid) ==" -ForegroundColor Cyan

# 1) Publicar el helper de update (self-contained single-file; su csproj ya lo define).
Write-Host "`n[1/4] Publicando AZCKeeperUpdater..." -ForegroundColor Yellow
dotnet publish (Join-Path $root "AZCKeeperUpdater\AZCKeeperUpdater.csproj") -c Release -r $Rid --self-contained -v q --nologo
$updaterExe = Join-Path $root "AZCKeeperUpdater\bin\Release\net8.0-windows\$Rid\publish\AZCKeeperUpdater.exe"
if (-not (Test-Path $updaterExe)) { throw "No se publico el updater: $updaterExe" }

# 2) Publicar el cliente (self-contained single-file). Embebe y copia el updater ya publicado.
Write-Host "`n[2/4] Publicando AZCKeeper4 (self-contained single-file)..." -ForegroundColor Yellow
dotnet publish (Join-Path $root "AZCKeeper.K4\AZCKeeper.K4.csproj") `
    -c Release -r $Rid --self-contained `
    -p:PublishSingleFile=true -p:Version=$Version `
    -v q --nologo
$clientPublish = Join-Path $root "AZCKeeper.K4\bin\Release\net8.0-windows\$Rid\publish"
$clientExe = Join-Path $clientPublish "AZCKeeper4.exe"
if (-not (Test-Path $clientExe)) { throw "No se publico el cliente: $clientExe" }

# 3) Ensamblar build\.
Write-Host "`n[3/4] Ensamblando build\..." -ForegroundColor Yellow
if (Test-Path $build) { Remove-Item $build -Recurse -Force }
New-Item -ItemType Directory -Path $build | Out-Null

# Setup = el cliente single-file (self-instalable, updater embebido).
$setup = Join-Path $build "AZCKeeper4-Setup.exe"
Copy-Item $clientExe $setup -Force

# ZIP de update = cliente + updater sueltos (lo que el helper extrae y copia).
$zip = Join-Path $build "AZCKeeper4-update.zip"
$staging = Join-Path $build "_zip"
New-Item -ItemType Directory -Path $staging | Out-Null
Copy-Item $clientExe (Join-Path $staging "AZCKeeper4.exe") -Force
Copy-Item $updaterExe (Join-Path $staging "AZCKeeperUpdater.exe") -Force
Compress-Archive -Path (Join-Path $staging "*") -DestinationPath $zip -Force
Remove-Item $staging -Recurse -Force

# 4) Resumen.
Write-Host "`n[4/4] Listo." -ForegroundColor Green
$setupMB = [math]::Round((Get-Item $setup).Length / 1MB, 1)
$zipMB   = [math]::Round((Get-Item $zip).Length / 1MB, 1)
Write-Host ("  {0}  ({1} MB)  <- Setup de un solo archivo, self-instalable" -f $setup, $setupMB)
Write-Host ("  {0}  ({1} MB)  <- subir al feed de update (keeper_client_releases)" -f $zip, $zipMB)
Write-Host "`nNada que instalar aparte: el runtime .NET va embebido (self-contained)." -ForegroundColor Cyan
