# AZCKeeper v4 - rescate independiente del agente.
#
# Por que existe: si una release rompe al propio mecanismo de update (p. ej. K3 bloqueo GitHub y no podia
# descargar la correccion), el agente no puede arreglarse solo. Este script NO depende del agente ni del runtime
# .NET 8 de Keeper (usa PowerShell 5.1 de Windows) y un --system-update NUNCA lo reemplaza: se instala una vez.
#
# Corre cada hora como SYSTEM (tarea "AZCKeeper Recovery"). Pasos:
#   1. Si el agente sincronizo en las ultimas 6 h (v4\health.json), no hace nada.
#   2. Si el servicio esta detenido, lo arranca y termina.
#   3. Como maximo una vez cada 24 h: descarga {servidor}/releases/recovery.json (Release firmada, JWS ES256 con la
#      clave de release anclada en recovery\trust.json), baja el paquete, verifica tamano y SHA-256 y lo instala
#      con Keeper.Bootstrapper --system-update (que a su vez tiene vuelta atras).
#   Sin red hacia el servidor no hace nada: sin red no se puede saber si el agente esta roto.
[CmdletBinding()]
param(
    [string]$Root = (Join-Path $env:ProgramData 'AZCKeeper'),
    [string]$ManifestFile = '',       # pruebas: manifiesto local en vez de descargarlo
    [string]$ManifestUrl = '',
    [switch]$Force,                   # pruebas: ignora salud y limite de 24 h
    [switch]$DryRun                   # verifica todo pero no instala
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ServiceName = 'KeeperAgent'
$RecoveryDir = Join-Path $Root 'recovery'
$DataDir = Join-Path $Root 'v4'

function Write-Log([string]$Message) {
    $dir = Join-Path $DataDir 'logs'
    try {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        $line = '{0} INFO  [Recovery] {1}' -f (Get-Date).ToUniversalTime().ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'"), $Message
        Add-Content -LiteralPath (Join-Path $dir ('recovery-' + (Get-Date).ToUniversalTime().ToString('yyyyMMdd') + '.log')) -Value $line -Encoding UTF8
    } catch { }
    Write-Output $Message
}

function ConvertFrom-Base64Url([string]$Value) {
    $s = $Value.Replace('-', '+').Replace('_', '/')
    switch ($s.Length % 4) { 2 { $s += '==' } 3 { $s += '=' } }
    return [Convert]::FromBase64String($s)
}

# Verifica ES256 (firma r||s de 64 bytes) con una clave P-256 en SPKI base64, usando CNG de .NET Framework.
function Test-Es256([byte[]]$Data, [byte[]]$Signature, [string]$SpkiBase64) {
    $spki = [Convert]::FromBase64String($SpkiBase64)
    if ($spki.Length -ne 91 -or $spki[26] -ne 4 -or $Signature.Length -ne 64) { return $false }
    $blob = New-Object byte[] 72
    [BitConverter]::GetBytes([uint32]0x31534345).CopyTo($blob, 0)   # BCRYPT_ECDSA_PUBLIC_P256_MAGIC ('ECS1')
    [BitConverter]::GetBytes([uint32]32).CopyTo($blob, 4)
    [Array]::Copy($spki, 27, $blob, 8, 64)                          # X || Y
    $key = [Security.Cryptography.CngKey]::Import($blob, [Security.Cryptography.CngKeyBlobFormat]::EccPublicBlob)
    try {
        $ecdsa = New-Object Security.Cryptography.ECDsaCng($key)
        $ecdsa.HashAlgorithm = [Security.Cryptography.CngAlgorithm]::Sha256
        return $ecdsa.VerifyData($Data, $Signature)
    } finally { $key.Dispose() }
}

function Test-ReleaseManifest($Release, $Trust) {
    $parts = $Release.manifest_jws.Split('.')
    if ($parts.Length -ne 3) { throw 'manifest_invalido' }
    $header = [Text.Encoding]::UTF8.GetString((ConvertFrom-Base64Url $parts[0])) | ConvertFrom-Json
    if ($header.alg -ne 'ES256' -or $header.kid -ne $Release.key_id) { throw 'jws_no_soportado' }
    $spki = $Trust.ReleasePublicKeys.($Release.key_id)
    if (-not $spki) { throw 'clave_no_confiable' }
    $signed = [Text.Encoding]::ASCII.GetBytes($parts[0] + '.' + $parts[1])
    if (-not (Test-Es256 $signed (ConvertFrom-Base64Url $parts[2]) $spki)) { throw 'firma_invalida' }
    $payload = [Text.Encoding]::UTF8.GetString((ConvertFrom-Base64Url $parts[1])) | ConvertFrom-Json
    foreach ($field in 'id', 'version', 'sequence', 'channel', 'architecture', 'artifact_url', 'size_bytes', 'sha256', 'key_id') {
        if ([string]$payload.$field -ne [string]$Release.$field) { throw "metadata_no_coincide:$field" }
    }
    if (-not $Release.artifact_url.StartsWith('https://')) { throw 'url_no_https' }
    return $payload
}

function Get-ApiBase {
    $env = (Get-ItemProperty -LiteralPath "HKLM:\SYSTEM\CurrentControlSet\Services\$ServiceName" -Name Environment -ErrorAction Stop).Environment
    $line = $env | Where-Object { $_ -like 'KEEPER_API_BASE=*' } | Select-Object -First 1
    if (-not $line) { throw 'sin_api_base' }
    return [Uri]$line.Substring(16)
}

# --- 1 y 2: salud y servicio ---
$statePath = Join-Path $RecoveryDir 'state.json'
if (-not $Force) {
    $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if (-not $service) { Write-Log 'Servicio KeeperAgent ausente: nada que rescatar.'; exit 0 }
    $healthPath = Join-Path $DataDir 'health.json'
    if (Test-Path -LiteralPath $healthPath) {
        $health = Get-Content -LiteralPath $healthPath -Raw | ConvertFrom-Json
        if ($health.last_sync_ok -and ((Get-Date).ToUniversalTime() - ([DateTimeOffset]::Parse($health.last_sync_ok)).UtcDateTime).TotalHours -lt 6) { exit 0 }
    }
    if ($service.Status -ne 'Running') {
        Write-Log "Servicio en estado $($service.Status): se arranca."
        Start-Service -Name $ServiceName
        exit 0
    }
    if (Test-Path -LiteralPath $statePath) {
        $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
        if (((Get-Date).ToUniversalTime() - ([DateTimeOffset]::Parse($state.last_attempt)).UtcDateTime).TotalHours -lt 24) { exit 0 }
    }
}

# --- 3: paquete conocido-bueno firmado ---
$trust = Get-Content -LiteralPath (Join-Path $RecoveryDir 'trust.json') -Raw | ConvertFrom-Json
try {
    if ($ManifestFile) { $release = Get-Content -LiteralPath $ManifestFile -Raw | ConvertFrom-Json }
    else {
        if (-not $ManifestUrl) { $api = Get-ApiBase; $ManifestUrl = '{0}://{1}/releases/recovery.json' -f $api.Scheme, $api.Authority }
        $release = (Invoke-WebRequest -Uri $ManifestUrl -UseBasicParsing -TimeoutSec 30).Content | ConvertFrom-Json
    }
} catch {
    Write-Log "Sin manifiesto de rescate ($($_.Exception.Message)): posiblemente sin red; no se hace nada."
    exit 0
}
Write-Log "Agente sin sync en mas de 6 h con el servidor alcanzable: rescate con release $($release.version) (sequence $($release.sequence))."
if (-not $DryRun) { @{ last_attempt = (Get-Date).ToUniversalTime().ToString('o') } | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding UTF8 }
try { Test-ReleaseManifest $release $trust | Out-Null } catch { Write-Log "Manifiesto rechazado: $($_.Exception.Message)"; exit 2 }
Write-Log 'Firma del manifiesto verificada con la clave de release anclada.'

$package = Join-Path $RecoveryDir 'package.zip'
Invoke-WebRequest -Uri $release.artifact_url -OutFile $package -UseBasicParsing -TimeoutSec 900
$size = (Get-Item -LiteralPath $package).Length
$hash = (Get-FileHash -LiteralPath $package -Algorithm SHA256).Hash.ToLowerInvariant()
if ($size -ne [int64]$release.size_bytes -or $hash -ne $release.sha256) {
    Remove-Item -LiteralPath $package -Force
    Write-Log "Paquete rechazado: tamano $size / sha256 $hash no coinciden con el manifiesto."
    exit 2
}
Write-Log "Paquete verificado ($size bytes, sha256 $hash)."
if ($DryRun) { Write-Log 'DRY-RUN: no se instala.'; exit 0 }

$extract = Join-Path $RecoveryDir 'package'
if (Test-Path -LiteralPath $extract) { Remove-Item -LiteralPath $extract -Recurse -Force }
Expand-Archive -LiteralPath $package -DestinationPath $extract -Force
$bootstrapper = Join-Path $extract 'agent\Keeper.Bootstrapper.exe'
if (-not (Test-Path -LiteralPath $bootstrapper)) { $bootstrapper = Join-Path $extract 'Keeper.Bootstrapper.exe' }
& $bootstrapper --system-update --payload (Join-Path $extract 'agent') | Out-Null
Write-Log "Keeper.Bootstrapper --system-update termino con codigo $LASTEXITCODE."
exit $LASTEXITCODE
