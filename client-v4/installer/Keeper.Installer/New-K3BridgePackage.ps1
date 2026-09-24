[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$LauncherPath,
    [Parameter(Mandatory)][string]$PayloadZip,
    [Parameter(Mandatory)][string]$ReleaseManifest,
    [Parameter(Mandatory)][string]$OutputZip
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$output = [IO.Path]::GetFullPath($OutputZip)
if (Test-Path -LiteralPath $output) { throw 'release_output_already_exists' }
$manifest = Get-Content -LiteralPath $ReleaseManifest -Raw | ConvertFrom-Json
$payload = Get-Item -LiteralPath $PayloadZip
if ($manifest.size_bytes -ne $payload.Length -or $manifest.sha256 -ne (Get-FileHash -LiteralPath $payload.FullName -Algorithm SHA256).Hash -or !$manifest.manifest_jws) { throw 'payload_manifest_mismatch' }
$archive = [IO.Compression.ZipFile]::OpenRead($payload.FullName)
try {
    foreach ($required in @('Keeper.msi','Keeper.Bridge.exe','Bootstrap.ps1','Migration.Journal.ps1','Migration.Enrollment.ps1','Migration.Native.cs','Entry.ps1','Integration.ps1','libsodium.dll','publisher.cer','deployment.json')) {
        if (!$archive.GetEntry($required)) { throw ('missing_bridge_artifact:' + $required) }
    }
} finally { $archive.Dispose() }
$launcher = (Resolve-Path -LiteralPath $LauncherPath).Path
$signature = Get-AuthenticodeSignature -LiteralPath $launcher
if (!$signature.SignerCertificate -or !$signature.TimeStamperCertificate -or $signature.Status -eq 'HashMismatch') { throw 'launcher_signature_required' }
$directory = [IO.Path]::GetDirectoryName($output)
New-Item -ItemType Directory -Path $directory -Force | Out-Null
$archive = [IO.Compression.ZipFile]::Open($output, 'Create')
try {
    [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $launcher, 'AZCKeeperUpdater.exe') | Out-Null
    [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $payload.FullName, 'bridge.zip') | Out-Null
    [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, (Resolve-Path -LiteralPath $ReleaseManifest).Path, 'release.json') | Out-Null
} finally { $archive.Dispose() }
# Publish this hash through an independent trusted channel; the legacy K3 receiver does not verify it.
(Get-FileHash -LiteralPath $output -Algorithm SHA256).Hash | Set-Content -LiteralPath ($output + '.sha256') -Encoding ASCII
if ((Get-Item -LiteralPath $output).Length -lt 1048576) { throw 'k3_requires_package_at_least_1mb' }
