[CmdletBinding()]
param(
    [ValidatePattern('^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$')]
    [string]$Version = '4.0.0',
    # Secuencia monotona de la release. Va al trust del paquete (InstalledSequence): el agente solo acepta
    # updates con secuencia MAYOR, asi que la instalacion inicial usa 1 y cada release sube.
    [ValidateRange(1, [long]::MaxValue)]
    [long]$Sequence = 1,
    [string]$Channel = 'stable',
    # Clave publica de release (Keeper.ReleaseTool keygen). Sin ella el paquete no lleva trust: el agente
    # instalado rechaza todo auto-update y no lanza Keeper.Session.
    [string]$ReleaseKeyPublic = '',
    # Opcional: con la privada y la URL HTTPS donde se alojara el ZIP, tambien firma la release.
    [string]$ReleaseKeyPrivate = '',
    [string]$ArtifactUrl = '',
    [string]$MinAgentVersion = '4.0.0',
    # Shared (defecto): agent\ con UNA copia del runtime y Agent+Session+Bootstrapper (~mitad de tamano).
    # Legacy: ejecutables single-file y bootstrapper en la raiz. Solo para la release de transicion a equipos
    # cuyo agente (<= 4.0.3) aun busca el bootstrapper en la raiz del paquete.
    [ValidateSet('Shared', 'Legacy')]
    [string]$Layout = 'Shared'
)

$ErrorActionPreference = 'Stop'
$env:MSBUILDDISABLENODEREUSE = '1'
$env:DOTNET_CLI_USE_MSBUILD_SERVER = '0'
$artifacts = Join-Path $PSScriptRoot 'artifacts'
$stage = Join-Path $artifacts ('.bootstrap-' + [Guid]::NewGuid().ToString('N'))
$package = Join-Path $stage 'package'
$destination = Join-Path $artifacts "AZCKeeper_v4_bootstrap_$Version.zip"
$buildFlags = @('-maxcpucount:1', '-nodeReuse:false', '-p:UseSharedCompilation=false')

try {
    New-Item -ItemType Directory -Path $package -Force | Out-Null
    $agent = Join-Path $package 'agent'
    $apps = @('Keeper.Agent', 'Keeper.Session', 'Keeper.Bootstrapper')
    if ($Layout -eq 'Shared') {
        # Los tres declaran el mismo conjunto de runtime (NETCore + WindowsDesktop), asi que publicar en la misma
        # carpeta deja una sola copia sin conflictos de version.
        foreach ($app in $apps) {
            & dotnet publish (Join-Path $PSScriptRoot "src/$app/$app.csproj") -c Release -r win-x64 --self-contained true -p:PublishSingleFile=false "-p:Version=$Version" -o $agent @buildFlags
            if ($LASTEXITCODE -ne 0) { throw "$app publish fallo: $LASTEXITCODE" }
        }
        foreach ($required in @('hostfxr.dll', 'coreclr.dll', 'System.Private.CoreLib.dll', 'System.Windows.Forms.dll')) {
            if (-not (Test-Path -LiteralPath (Join-Path $agent $required))) { throw "Runtime incompleto en agent\: falta $required" }
        }
        foreach ($app in $apps) {
            if (-not (Test-Path -LiteralPath (Join-Path $agent "$app.exe"))) { throw "Falta $app.exe" }
            $runtimeConfig = Get-Content -LiteralPath (Join-Path $agent "$app.runtimeconfig.json") -Raw | ConvertFrom-Json
            $frameworks = @($runtimeConfig.runtimeOptions.includedFrameworks | ForEach-Object name)
            if ($runtimeConfig.runtimeOptions.framework -or -not ($frameworks -contains 'Microsoft.NETCore.App') -or -not ($frameworks -contains 'Microsoft.WindowsDesktop.App')) {
                throw "$app no es self-contained con NETCore+WindowsDesktop"
            }
            Write-Output ("Self-contained (runtime compartido) verificado: {0}, {1}" -f $app, ($frameworks -join ' + '))
        }
    }
    else {
        foreach ($app in @('Keeper.Agent', 'Keeper.Session')) {
            & dotnet publish (Join-Path $PSScriptRoot "src/$app/$app.csproj") -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=false "-p:Version=$Version" -o $agent @buildFlags
            if ($LASTEXITCODE -ne 0) { throw "$app publish fallo: $LASTEXITCODE" }
        }
        & dotnet publish (Join-Path $PSScriptRoot 'src/Keeper.Bootstrapper/Keeper.Bootstrapper.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=false "-p:Version=$Version" -o $package @buildFlags
        if ($LASTEXITCODE -ne 0) { throw "Bootstrapper publish fallo: $LASTEXITCODE" }
        foreach ($entry in @(@{ Name = 'Keeper.Agent'; Directory = $agent }, @{ Name = 'Keeper.Session'; Directory = $agent }, @{ Name = 'Keeper.Bootstrapper'; Directory = $package })) {
            $executable = Join-Path $entry.Directory ($entry.Name + '.exe')
            if ((Get-Item -LiteralPath $executable).Length -lt 10MB) { throw "Bundle incompleto: $executable" }
            $runtimeConfigPath = Join-Path $PSScriptRoot ("src/{0}/bin/Release/net8.0-windows/win-x64/{0}.runtimeconfig.json" -f $entry.Name)
            $runtimeConfig = Get-Content -LiteralPath $runtimeConfigPath -Raw | ConvertFrom-Json
            if ($runtimeConfig.runtimeOptions.framework -or -not ($runtimeConfig.runtimeOptions.includedFrameworks | Where-Object name -EQ 'Microsoft.NETCore.App')) {
                throw "El publish depende de un runtime externo: $executable"
            }
            Write-Output ("Self-contained single-file verificado: {0}" -f $entry.Name)
        }
    }
    $releaseTool = Join-Path $PSScriptRoot 'tools/Keeper.ReleaseTool/Keeper.ReleaseTool.csproj'
    if ($ReleaseKeyPublic) {
        & dotnet build $releaseTool -c Release @buildFlags | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "ReleaseTool build fallo: $LASTEXITCODE" }
        & dotnet run --project $releaseTool -c Release --no-build -- trust --public $ReleaseKeyPublic --payload $agent --sequence $Sequence --channel $Channel
        if ($LASTEXITCODE -ne 0) { throw "Trust fallo: $LASTEXITCODE" }
    }
    else {
        Write-Warning 'Sin -ReleaseKeyPublic: el paquete NO lleva installation-trust.json. El agente rechazara todo auto-update y no lanzara Keeper.Session.'
    }
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'src/Keeper.Bootstrapper/installation.example.json') -Destination (Join-Path $package 'installation.json')
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'src/Keeper.Bootstrapper/README.md') -Destination (Join-Path $package 'README.md')
    $bootstrapperPath = if ($Layout -eq 'Shared') { 'agent\Keeper.Bootstrapper.exe' } else { 'Keeper.Bootstrapper.exe' }
    $launcher = "@echo off`r`n`"%~dp0$bootstrapperPath`" %*`r`nexit /b %errorlevel%`r`n"
    [IO.File]::WriteAllText((Join-Path $package 'install.cmd'), $launcher, [Text.Encoding]::ASCII)

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stagedZip = Join-Path $stage 'installer.zip'
    [IO.Compression.ZipFile]::CreateFromDirectory($package, $stagedZip, [IO.Compression.CompressionLevel]::Optimal, $false)
    Move-Item -LiteralPath $stagedZip -Destination $destination -Force
    Write-Output "ZIP: $destination"
    Write-Output "Bytes: $((Get-Item -LiteralPath $destination).Length)"
    Write-Output "SHA256: $((Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash)"
    if ($ReleaseKeyPrivate -or $ArtifactUrl) {
        if (-not ($ReleaseKeyPublic -and $ReleaseKeyPrivate -and $ArtifactUrl)) { throw 'Firmar requiere -ReleaseKeyPublic, -ReleaseKeyPrivate y -ArtifactUrl.' }
        $releaseJson = Join-Path $artifacts "release-$Version.json"
        & dotnet run --project $releaseTool -c Release --no-build -- sign --key $ReleaseKeyPrivate --public $ReleaseKeyPublic --package $destination --version $Version --sequence $Sequence --url $ArtifactUrl --channel $Channel --min-agent $MinAgentVersion --out $releaseJson
        if ($LASTEXITCODE -ne 0) { throw "Firma fallo: $LASTEXITCODE" }
    }
}
finally {
    & dotnet build-server shutdown
    if (Test-Path -LiteralPath $stage) {
        $resolvedStage = (Resolve-Path -LiteralPath $stage).Path
        $resolvedArtifacts = (Resolve-Path -LiteralPath $artifacts).Path
        if (-not $resolvedStage.StartsWith($resolvedArtifacts + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or
            -not ([IO.Path]::GetFileName($resolvedStage)).StartsWith('.bootstrap-', [StringComparison]::Ordinal)) {
            throw "Limpieza rechazada: ruta fuera del staging esperado ($resolvedStage)."
        }
        Remove-Item -LiteralPath $resolvedStage -Recurse -Force
    }
}
