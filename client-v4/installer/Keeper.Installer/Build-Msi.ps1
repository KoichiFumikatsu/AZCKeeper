#Requires -Version 5.1
<#
.SYNOPSIS
    Compila el MSI de AZCKeeper v4 de forma repetible.

.DESCRIPTION
    Publica Keeper.Agent y Keeper.Session en una carpeta comun, genera el fragmento
    de componentes a partir de lo realmente publicado y construye el MSI.

    Por que se genera el fragmento en vez de usar <Files Include=...>: el harvest
    automatico de WiX resolvia la ruta contra el nombre del directorio de destino
    y empaquetaba UNICAMENTE Keeper.Agent.exe, produciendo un MSI que instalaba un
    servicio incapaz de arrancar (sin DLLs ni runtimeconfig). Enumerar los archivos
    aqui es explicito y verificable.

    Se usa WiX 5 a proposito: WiX 6 y 7 exigen aceptar la licencia de pago
    Open Source Maintenance Fee para uso comercial.
#>
[CmdletBinding()]
param(
    [string]$Configuration = 'Release',
    [string]$Runtime = 'win-x64',
    [string]$OutputDirectory,
    # Firma opcional: huella de un certificado de firma de codigo ya presente en el almacen.
    [string]$SigningThumbprint,
    [string]$TimestampUrl = 'http://timestamp.digicert.com'
)
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if (-not $OutputDirectory) { $OutputDirectory = Join-Path $root 'artifacts' }
$publish = Join-Path $OutputDirectory 'publish'
$msi = Join-Path $OutputDirectory 'AZCKeeper-v4.msi'
$generated = Join-Path $PSScriptRoot 'Files.g.wxs'

if (Test-Path -LiteralPath $publish) { Remove-Item -LiteralPath $publish -Recurse -Force }
$null = New-Item -ItemType Directory -Path $publish -Force

# Los nodos de MSBuild agotan la memoria de estas maquinas: un solo nodo, sin reutilizar.
$env:MSBUILDDISABLENODEREUSE = '1'
foreach ($project in @('src\Keeper.Agent\Keeper.Agent.csproj', 'src\Keeper.Session\Keeper.Session.csproj')) {
    Write-Host "publicando $project"
    & dotnet publish (Join-Path $root $project) -c $Configuration -r $Runtime --self-contained false `
        -o $publish -maxcpucount:1 -nodeReuse:false -v:q --nologo
    if ($LASTEXITCODE -ne 0) { throw "Fallo la publicacion de $project" }
}
& dotnet build-server shutdown | Out-Null

# Keeper.Agent.exe lo declara Package.wxs porque lleva el ServiceInstall; el resto va aqui.
$files = Get-ChildItem -LiteralPath $publish -Recurse -File |
    Where-Object { $_.Extension -ne '.pdb' -and $_.Name -ne 'Keeper.Agent.exe' } |
    Sort-Object FullName
if (-not $files) { throw 'No hay archivos publicados que empaquetar.' }
if (-not ($files | Where-Object Name -eq 'Keeper.Session.exe')) {
    throw 'Falta Keeper.Session.exe: el SessionSupervisor lo busca junto al agente.'
}

$lines = @(
    '<?xml version="1.0" encoding="utf-8"?>'
    '<!-- GENERADO por Build-Msi.ps1. No editar a mano: se reescribe en cada compilacion. -->'
    '<Wix xmlns="http://wixtoolset.org/schemas/v4/wxs">'
    '  <Fragment>'
    '    <ComponentGroup Id="AgentPayload" Directory="INSTALLFOLDER">'
)
$used = @{}
foreach ($file in $files) {
    $relative = $file.FullName.Substring($publish.Length).TrimStart('\')
    $id = 'f_' + ($relative -replace '[^A-Za-z0-9_]', '_')
    if ($id.Length -gt 70) { $id = $id.Substring(0, 70) }
    $candidate = $id; $n = 1
    while ($used.ContainsKey($candidate)) { $n++; $candidate = "$id`_$n" }
    $used[$candidate] = $true
    $subdirectory = Split-Path $relative -Parent
    $attribute = if ($subdirectory) { " Subdirectory=""$([Security.SecurityElement]::Escape($subdirectory))""" } else { '' }
    $source = [Security.SecurityElement]::Escape($file.FullName)
    $lines += "      <Component Id=""$candidate"" Bitness=""always64""$attribute>"
    $lines += "        <File Id=""$candidate`_file"" Source=""$source"" KeyPath=""yes"" />"
    $lines += '      </Component>'
}
$lines += @('    </ComponentGroup>', '  </Fragment>', '</Wix>')
Set-Content -LiteralPath $generated -Value $lines -Encoding UTF8
Write-Host "componentes generados: $($files.Count)"

if (Test-Path -LiteralPath $msi) { Remove-Item -LiteralPath $msi -Force }
& wix build (Join-Path $PSScriptRoot 'Package.wxs') $generated `
    -ext WixToolset.Util.wixext -bindpath "publish=$publish" -arch x64 -o $msi
if ($LASTEXITCODE -ne 0) { throw 'Fallo la compilacion del MSI.' }

# Verificacion real: se extrae el MSI y se cuenta lo que realmente lleva dentro.
# Un MSI que compila sin errores puede empaquetar un solo archivo; eso ya paso una vez.
$verify = Join-Path $OutputDirectory 'verify'
if (Test-Path -LiteralPath $verify) { Remove-Item -LiteralPath $verify -Recurse -Force }
$null = New-Item -ItemType Directory -Path $verify -Force
$extract = Start-Process -FilePath "$env:SystemRoot\System32\msiexec.exe" -Wait -PassThru -WindowStyle Hidden `
    -ArgumentList @('/a', "`"$msi`"", '/qn', "TARGETDIR=`"$verify`"")
if ($extract.ExitCode -ne 0) { throw "No se pudo extraer el MSI para verificarlo (codigo $($extract.ExitCode))." }
$packaged = @(Get-ChildItem -LiteralPath $verify -Recurse -File | Where-Object Extension -ne '.msi')
$expected = $files.Count + 1
if ($packaged.Count -ne $expected) { throw "El MSI lleva $($packaged.Count) archivos y se esperaban $expected." }
foreach ($required in @('Keeper.Agent.exe', 'Keeper.Session.exe', 'Keeper.Agent.runtimeconfig.json')) {
    if (-not ($packaged | Where-Object Name -eq $required)) { throw "El MSI no incluye $required." }
}
Remove-Item -LiteralPath $verify -Recurse -Force

if ($SigningThumbprint) {
    & signtool.exe sign /sha1 $SigningThumbprint /fd SHA256 /tr $TimestampUrl /td SHA256 $msi
    if ($LASTEXITCODE -ne 0) { throw 'Fallo la firma del MSI.' }
}

[pscustomobject]@{ Msi = $msi; Files = $packaged.Count; Signed = [bool]$SigningThumbprint }
