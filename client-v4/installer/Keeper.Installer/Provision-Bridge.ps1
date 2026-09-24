#Requires -Version 5.1
<#
.SYNOPSIS
    Genera el archivo de confianza del lanzador puente y lo compila con el pin incrustado.

.DESCRIPTION
    El lanzador no confia en el almacen de Windows: lleva incrustado (como recurso, no como
    archivo suelto) el SHA-256 del certificado de AZC y los hashes de los binarios del agente.
    Asi un paquete descargado no puede nominar su propio certificado.

    Ejecutar DESPUES de Build-Msi.ps1, porque los hashes se calculan sobre lo publicado.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$CertificateThumbprint,
    [string]$OutputDirectory,
    [string]$Channel = 'stable',
    [long]$InstalledSequence = 1,
    [string]$SignToolPath
)
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $root 'artifacts' }
$publish = Join-Path $OutputDirectory 'publish'
if (-not (Test-Path -LiteralPath $publish)) { throw 'Falta artifacts\publish: ejecuta antes Build-Msi.ps1.' }

$certificate = Get-Item -LiteralPath ('Cert:\CurrentUser\My\' + $CertificateThumbprint)
if (-not $certificate.HasPrivateKey) { throw 'El certificado no tiene clave privada en este equipo.' }
$sha = [Security.Cryptography.SHA256]::Create()
try { $certificateSha256 = ([BitConverter]::ToString($sha.ComputeHash($certificate.RawData))).Replace('-', '') }
finally { $sha.Dispose() }

# TamperGuard compara SHA-256 en hexadecimal de 64 caracteres, con rutas relativas a la instalacion.
$binaryHashes = [ordered]@{}
foreach ($file in Get-ChildItem -LiteralPath $publish -Recurse -File -Include *.exe, *.dll | Sort-Object FullName) {
    $relative = $file.FullName.Substring($publish.Length).TrimStart('\')
    $binaryHashes[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
}
if ($binaryHashes.Count -eq 0) { throw 'No se encontraron binarios que anclar.' }

$trustFile = Join-Path $OutputDirectory 'bridge-trust.provisioned.json'
[pscustomobject]@{
    Trust = [pscustomobject]@{
        ReleasePublicKeys = [pscustomobject]@{}
        BinaryHashes      = [pscustomobject]$binaryHashes
        InstalledSequence = $InstalledSequence
        Channel           = $Channel
    }
    Publisher = [pscustomobject]@{ CertificateSha256 = $certificateSha256; Subject = $certificate.Subject }
} | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath $trustFile -Encoding UTF8
Write-Host "confianza provisionada: $($binaryHashes.Count) binarios anclados"

$bridge = Join-Path $OutputDirectory 'bridge'
if (Test-Path -LiteralPath $bridge) { Remove-Item -LiteralPath $bridge -Recurse -Force }
$env:MSBUILDDISABLENODEREUSE = '1'
& dotnet publish (Join-Path $root 'installer\Keeper.Bridge\Keeper.Bridge.csproj') -c Release -r win-x64 `
    --self-contained false -o $bridge -p:BridgeTrustFile=$trustFile -maxcpucount:1 -nodeReuse:false -v:q --nologo
if ($LASTEXITCODE -ne 0) { throw 'Fallo la compilacion del lanzador.' }
& dotnet build-server shutdown | Out-Null

$launcher = Join-Path $bridge 'Keeper.Bridge.exe'
if (-not (Test-Path -LiteralPath $launcher)) { throw 'No se genero Keeper.Bridge.exe.' }

if ($SignToolPath) {
    & (Join-Path $PSScriptRoot 'Sign-AzcRelease.ps1') -CertificateThumbprint $CertificateThumbprint `
        -MsiPath (Join-Path $OutputDirectory 'AZCKeeper-v4.msi') -LauncherPath $launcher `
        -ScriptPaths @((Join-Path $PSScriptRoot 'Bootstrap.ps1'), (Join-Path $PSScriptRoot 'Entry.ps1'), (Join-Path $PSScriptRoot 'Migration.Journal.ps1')) `
        -SignToolPath $SignToolPath | Out-Null
}

# El estado sera 'UnknownError' mientras la raiz autofirmada no este instalada: es lo esperado
# y el lanzador lo tolera solo para certificados autofirmados ya anclados por SHA-256.
$signature = Get-AuthenticodeSignature -LiteralPath $launcher
[pscustomobject]@{
    Launcher          = $launcher
    AnchoredBinaries  = $binaryHashes.Count
    CertificateSha256 = $certificateSha256
    SignatureStatus   = $signature.Status
    Timestamped       = [bool]$signature.TimeStamperCertificate
}
