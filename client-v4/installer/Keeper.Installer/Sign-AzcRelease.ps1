[CmdletBinding(DefaultParameterSetName = 'Sign')]
param(
    [Parameter(Mandatory, ParameterSetName = 'Create')][switch]$CreateCertificate,
    [Parameter(Mandatory, ParameterSetName = 'Create')][string]$PublicOutputDirectory,
    [Parameter(Mandatory, ParameterSetName = 'Sign')][string]$CertificateThumbprint,
    [Parameter(Mandatory, ParameterSetName = 'Sign')][string]$MsiPath,
    [Parameter(Mandatory, ParameterSetName = 'Sign')][string]$LauncherPath,
    [Parameter(ParameterSetName = 'Sign')][string[]]$ScriptPaths = @(),
    [Parameter(ParameterSetName = 'Sign')][uri]$TimestampUrl = 'http://timestamp.digicert.com',
    [Parameter(ParameterSetName = 'Sign')][string]$SignToolPath = 'signtool.exe'
)
$ErrorActionPreference = 'Stop'
if ($CreateCertificate) {
    New-Item -ItemType Directory -Path $PublicOutputDirectory -Force | Out-Null
    if (Test-Path -LiteralPath (Join-Path $PublicOutputDirectory 'publisher.cer')) { throw 'publisher_already_provisioned' }
    $certificate = New-SelfSignedCertificate -Type CodeSigningCert -Subject 'CN=Grupo AZC' -FriendlyName 'AZC Keeper release signing' `
        -CertStoreLocation 'Cert:\CurrentUser\My' -KeyAlgorithm RSA -KeyLength 3072 -HashAlgorithm SHA256 `
        -KeyExportPolicy NonExportable -NotAfter (Get-Date).AddYears(3)
    Export-Certificate -Cert $certificate -FilePath (Join-Path $PublicOutputDirectory 'publisher.cer') | Out-Null
    $sha = [Security.Cryptography.SHA256]::Create()
    try { $hash = ([BitConverter]::ToString($sha.ComputeHash($certificate.RawData))).Replace('-', '') } finally { $sha.Dispose() }
    [pscustomobject]@{ Subject = $certificate.Subject; CertificateSha256 = $hash; Thumbprint = $certificate.Thumbprint } |
        ConvertTo-Json | Set-Content -LiteralPath (Join-Path $PublicOutputDirectory 'publisher-public.json') -Encoding UTF8
    # Only public metadata leaves the signing workstation; the private key stays non-exportable in its CNG store.
    Write-Output ('Certificado publico: ' + (Join-Path $PublicOutputDirectory 'publisher-public.json'))
    return
}
$certificate = Get-Item -LiteralPath ('Cert:\CurrentUser\My\' + $CertificateThumbprint)
if (!$certificate.HasPrivateKey -or $certificate.Subject -ne 'CN=Grupo AZC' -or $certificate.NotAfter -le (Get-Date)) { throw 'invalid_signing_certificate' }
$signTool = (Get-Command $SignToolPath -ErrorAction Stop).Source
foreach ($path in @($MsiPath, $LauncherPath)) {
    $resolved = (Resolve-Path -LiteralPath $path).Path
    & $signTool sign /sha1 $CertificateThumbprint /s My /fd SHA256 /tr $TimestampUrl.AbsoluteUri /td SHA256 $resolved
    if ($LASTEXITCODE -ne 0) { throw 'authenticode_sign_failed' }
    $signature = Get-AuthenticodeSignature -LiteralPath $resolved
    if (!$signature.SignerCertificate -or $signature.SignerCertificate.Thumbprint -ne $CertificateThumbprint -or !$signature.TimeStamperCertificate) { throw 'signature_or_timestamp_missing' }
}
foreach ($path in $ScriptPaths) {
    $signed = Set-AuthenticodeSignature -LiteralPath (Resolve-Path -LiteralPath $path).Path -Certificate $certificate -HashAlgorithm SHA256 -TimestampServer $TimestampUrl.AbsoluteUri
    if (!$signed.SignerCertificate -or $signed.SignerCertificate.Thumbprint -ne $CertificateThumbprint -or !$signed.TimeStamperCertificate) { throw 'script_sign_failed' }
}
