[CmdletBinding()]
param(
    [ValidatePattern('^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$')]
    [string]$Version = '4.0.0'
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
    & dotnet publish (Join-Path $PSScriptRoot 'src/Keeper.Agent/Keeper.Agent.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=false "-p:Version=$Version" -o $agent @buildFlags
    if ($LASTEXITCODE -ne 0) { throw "Agent publish fallo: $LASTEXITCODE" }
    & dotnet publish (Join-Path $PSScriptRoot 'src/Keeper.Bootstrapper/Keeper.Bootstrapper.csproj') -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=false "-p:Version=$Version" -o $package @buildFlags
    if ($LASTEXITCODE -ne 0) { throw "Bootstrapper publish fallo: $LASTEXITCODE" }

    foreach ($entry in @(@{ Name = 'Keeper.Agent'; Directory = $agent }, @{ Name = 'Keeper.Bootstrapper'; Directory = $package })) {
        $executable = Join-Path $entry.Directory ($entry.Name + '.exe')
        if ((Get-Item -LiteralPath $executable).Length -lt 10MB) { throw "Bundle incompleto: $executable" }
        $runtimeConfigPath = Join-Path $PSScriptRoot ("src/{0}/bin/Release/net8.0/win-x64/{0}.runtimeconfig.json" -f $entry.Name)
        $runtimeConfig = Get-Content -LiteralPath $runtimeConfigPath -Raw | ConvertFrom-Json
        if ($runtimeConfig.runtimeOptions.framework -or -not ($runtimeConfig.runtimeOptions.includedFrameworks | Where-Object name -EQ 'Microsoft.NETCore.App')) {
            throw "El publish depende de un runtime externo: $executable"
        }
        $stream = [IO.File]::OpenRead($executable)
        try {
            $tailLength = [int][Math]::Min(1MB, $stream.Length)
            [void]$stream.Seek(-$tailLength, [IO.SeekOrigin]::End)
            $buffer = New-Object byte[] $tailLength
            $offset = 0
            while ($offset -lt $tailLength) {
                $read = $stream.Read($buffer, $offset, $tailLength - $offset)
                if ($read -eq 0) { throw "Bundle truncado: $executable" }
                $offset += $read
            }
            $manifest = [Text.Encoding]::UTF8.GetString($buffer)
            foreach ($assembly in @('System.Private.CoreLib.dll', 'System.Runtime.dll', ($entry.Name + '.runtimeconfig.json'))) {
                if (-not $manifest.Contains($assembly)) { throw "Runtime/manifest ausente del bundle: $assembly" }
            }
        }
        finally { $stream.Dispose() }
        Write-Output ("Self-contained verificado: {0}, Microsoft.NETCore.App {1}, CoreLib incluida" -f $entry.Name, $runtimeConfig.runtimeOptions.includedFrameworks[0].version)
    }
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'src/Keeper.Bootstrapper/installation.example.json') -Destination (Join-Path $package 'installation.json')
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'src/Keeper.Bootstrapper/README.md') -Destination (Join-Path $package 'README.md')
    $launcher = "@echo off`r`n`"%~dp0Keeper.Bootstrapper.exe`" %*`r`nexit /b %errorlevel%`r`n"
    [IO.File]::WriteAllText((Join-Path $package 'install.cmd'), $launcher, [Text.Encoding]::ASCII)

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stagedZip = Join-Path $stage 'installer.zip'
    [IO.Compression.ZipFile]::CreateFromDirectory($package, $stagedZip, [IO.Compression.CompressionLevel]::Optimal, $false)
    Move-Item -LiteralPath $stagedZip -Destination $destination -Force
    Write-Output "ZIP: $destination"
    Write-Output "Bytes: $((Get-Item -LiteralPath $destination).Length)"
    Write-Output "SHA256: $((Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash)"
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
