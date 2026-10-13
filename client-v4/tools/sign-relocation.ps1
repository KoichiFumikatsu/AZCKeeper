[CmdletBinding()]
param(
    # Misma clave que build-installer.ps1 -ReleaseKeyPrivate / -ReleaseKeyPublic (Keeper.ReleaseTool keygen). La privada es
    # DPAPI del usuario de Windows que la creo: solo se puede firmar desde ese perfil.
    [Parameter(Mandatory = $true)][string]$ReleaseKeyPrivate,
    [Parameter(Mandatory = $true)][string]$ReleaseKeyPublic,
    # tenant_id a los que aplica la mudanza (separados por coma o como lista).
    [Parameter(Mandatory = $true)][string[]]$Tenants,
    # Monotona: mayor que cualquier mudanza ya aplicada (el agente rechaza una igual o menor). La primera: 1.
    [Parameter(Mandatory = $true)][ValidateRange(1, [long]::MaxValue)][long]$Sequence,
    # device_id que NO se mudan (los equipos de prueba que se quedan en devkeep).
    [string[]]$ExcludeDevices = @(),
    [string]$From = 'https://devkeep.azclegal.com/',
    [string]$To = 'https://keep.azclegal.com/',
    # UTC ISO 8601 (p. ej. 2026-10-17T00:00:00Z). Sin valor: ahora.
    [string]$NotBefore = '',
    [string]$ExpiresAt = '',
    [ValidateRange(1, 90)][int]$Days = 14,
    [string]$Out = ''
)
# Firma una orden de mudanza de servidor (agente 4.0.24+) con la clave de releases y deja el cuerpo de POST /relocations
# (registrar desde Panel > Releases > Mudanzas de servidor). No registra ni despliega nada.
# Uso (desde client-v4):
#   powershell -File tools/sign-relocation.ps1 -ReleaseKeyPrivate C:\keeper-keys\release-signing-key.dpapi `
#     -ReleaseKeyPublic C:\keeper-keys\release-key.public.json -Tenants 6e888b88-b3ef-4ac8-adae-d2fcf12d6b12 `
#     -ExcludeDevices <dev1>,<dev2>,<dev3> -Sequence 1 -NotBefore 2026-10-17T00:00:00Z -Days 14
$ErrorActionPreference = 'Stop'
$env:MSBUILDDISABLENODEREUSE = '1'
$env:DOTNET_CLI_USE_MSBUILD_SERVER = '0'
$root = Split-Path -Parent $PSScriptRoot
if (-not $Out) { $Out = Join-Path $root ("artifacts/relocation-{0}.json" -f $Sequence) }
$null = New-Item -ItemType Directory -Force -Path (Split-Path -Parent ([IO.Path]::GetFullPath($Out)))
$tool = Join-Path $PSScriptRoot 'Keeper.ReleaseTool/Keeper.ReleaseTool.csproj'
& dotnet build $tool -c Release -nologo -v q -m:1 -nodeReuse:false | Out-Null
if ($LASTEXITCODE -ne 0) { throw "ReleaseTool build fallo: $LASTEXITCODE" }
$arguments = @('relocation', '--key', $ReleaseKeyPrivate, '--public', $ReleaseKeyPublic, '--tenants', (($Tenants -split ',') -join ','),
    '--sequence', $Sequence, '--from', $From, '--to', $To, '--out', $Out)
$exclude = @($ExcludeDevices | ForEach-Object { $_ -split ',' } | Where-Object { $_ })
if ($exclude.Count -gt 0) { $arguments += @('--exclude', ($exclude -join ',')) }
if ($NotBefore) { $arguments += @('--not-before', $NotBefore) }
if ($ExpiresAt) { $arguments += @('--expires-at', $ExpiresAt) } else { $arguments += @('--days', $Days) }
& dotnet run --project $tool -c Release --no-build -- @arguments
if ($LASTEXITCODE -ne 0) { throw "Firma de la mudanza fallo: $LASTEXITCODE" }
