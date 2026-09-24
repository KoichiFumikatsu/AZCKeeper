param(
    [string]$MySqlDirectory = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64',
    [string]$PhpPath = 'php',
    [switch]$CorruptLoginSignature
)
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$backend = Join-Path $repo 'backend-v4'
$runRoot = Join-Path $PSScriptRoot ('.runs/' + [guid]::NewGuid().ToString('N'))
$processes = [Collections.Generic.List[Diagnostics.Process]]::new()
$savedEnv = @{}
$mysqlProcess = $null
$dotnet = $null
$failure = $null
$cleanupErrors = [Collections.Generic.List[string]]::new()

function Set-TestEnv([string]$Name, [string]$Value) {
    if (-not $savedEnv.ContainsKey($Name)) { $savedEnv[$Name] = [Environment]::GetEnvironmentVariable($Name) }
    [Environment]::SetEnvironmentVariable($Name, $Value)
}
function Free-Port {
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { return $listener.LocalEndpoint.Port } finally { $listener.Stop() }
}
function Start-Owned([string]$File, [string[]]$Arguments, [string]$Name) {
    $p = Start-Process -FilePath $File -ArgumentList $Arguments -WorkingDirectory $repo -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runRoot "$Name.out") -RedirectStandardError (Join-Path $runRoot "$Name.err")
    $null = $p.Handle
    $processes.Add($p)
    return $p
}
function Invoke-Owned([string]$File, [string[]]$Arguments, [string]$Name, [int]$TimeoutSeconds = 120) {
    $p = Start-Owned $File $Arguments $Name
    $deadline = [DateTime]::UtcNow.AddSeconds($TimeoutSeconds)
    while (-not $p.WaitForExit(500)) {
        if ([DateTime]::UtcNow -gt $deadline) { throw "$Name timed out after $TimeoutSeconds seconds" }
    }
    foreach ($suffix in @('out', 'err')) {
        $log = Join-Path $runRoot "$Name.$suffix"
        if ((Get-Item -LiteralPath $log).Length -gt 0) { Get-Content -LiteralPath $log }
    }
    if ($p.ExitCode -ne 0) { throw "$Name exited with code $($p.ExitCode)" }
}
function Quote-Path([string]$Path) { return '"' + $Path + '"' }
function Stop-OwnedTree([Diagnostics.Process]$Process) {
    if ($Process.HasExited) { return }
    # /T includes compiler children on timeout; only the process created by this run is targeted.
    if ($Process.ProcessName -eq 'dotnet') {
        try { & taskkill.exe /PID $Process.Id /T /F | Out-Null } catch { Write-Output "Stopping owned process $($Process.Id) directly." }
    }
    if (-not $Process.HasExited) { Stop-Process -Id $Process.Id -Force }
    $null = $Process.WaitForExit(10000)
    if (-not $Process.HasExited) { throw "Process $($Process.Id) still alive" }
}

try {
    $null = New-Item -ItemType Directory -Path (Join-Path $runRoot 'data') -Force
    $php = (Get-Command $PhpPath).Source
    $dotnet = (Get-Command dotnet).Source
    $mysql = (Resolve-Path (Join-Path $MySqlDirectory 'bin/mysqld.exe')).Path
    $mysqlPort = Free-Port
    do { $httpPort = Free-Port } while ($httpPort -eq $mysqlPort)
    Set-TestEnv 'MSBUILDDISABLENODEREUSE' '1'
    Set-TestEnv 'DOTNET_CLI_TELEMETRY_OPTOUT' '1'
    Set-TestEnv 'DOTNET_GCHeapHardLimit' '0x20000000'
    Set-TestEnv 'KEEPER_INTEGRATION_ROOT' $runRoot
    Set-TestEnv 'KEEPER_DB_DSN' "mysql:host=127.0.0.1;port=$mysqlPort;dbname=keeper_v4_integration_test;charset=utf8mb4"
    Set-TestEnv 'KEEPER_DB_USER' 'root'
    Set-TestEnv 'KEEPER_DB_PASSWORD' ''
    Set-TestEnv 'KEEPER_DB_PASSWORD_FILE' ''
    Set-TestEnv 'KEEPER_ORIGIN' "https://127.0.0.1:$httpPort"
    Set-TestEnv 'KEEPER_TRUSTED_PROXY_IPS' '127.0.0.1'
    Set-TestEnv 'KEEPER_ALLOW_HTTP_LOCAL' '0'
    Set-TestEnv 'KEEPER_RATE_DIR' (Join-Path $runRoot 'rate')
    Set-TestEnv 'KEEPER_DEVICE_RATE' '12000'
    Set-TestEnv 'KEEPER_DEVICE_BURST' '100'
    Set-TestEnv 'KEEPER_BOOTSTRAP_RATE' '12000'
    Set-TestEnv 'KEEPER_BOOTSTRAP_BURST' '100'
    Set-TestEnv 'KEEPER_CHALLENGE_OUTSTANDING' '10'
    Set-TestEnv 'KEEPER_RELEASE_KEYS_FILE' (Join-Path $runRoot 'release-keys.json')
    Set-TestEnv 'KEEPER_ESCROW_KEY_FILE' (Join-Path $runRoot 'unused-escrow.key')
    $responseKey = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($responseKey) } finally { $rng.Dispose() }
    Set-TestEnv 'KEEPER_RESPONSE_KEY' ([Convert]::ToBase64String($responseKey))
    Set-TestEnv 'KEEPER_RESPONSE_KEY_ID' 'integration-ephemeral'

    $project = Join-Path $repo 'client-v4/tests/Keeper.Agent.IntegrationHarness/Keeper.Agent.IntegrationHarness.csproj'
    # Build first, so MySQL and the compiler do not compete for memory.
    Invoke-Owned $dotnet @('build', (Quote-Path $project), '-maxcpucount:1', '-nodeReuse:false', '-p:UseSharedCompilation=false', '--artifacts-path', (Quote-Path (Join-Path $runRoot 'artifacts')), '--verbosity', 'minimal') 'build' 240
    $harness = Join-Path $runRoot 'artifacts/bin/Keeper.Agent.IntegrationHarness/debug/Keeper.Agent.IntegrationHarness.exe'
    Invoke-Owned $harness @('keygen', (Quote-Path $runRoot)) 'keygen'

    $common = @('--no-defaults', ('--datadir=' + (Quote-Path (Join-Path $runRoot 'data'))), ('--tmpdir=' + (Quote-Path $runRoot)),
        '--skip-log-bin', '--mysqlx=0', '--performance-schema=OFF', '--innodb-buffer-pool-size=64M', '--innodb-redo-log-capacity=32M',
        '--max-connections=12', '--table-open-cache=128', '--table-definition-cache=512', '--tmp-table-size=16M', '--max-heap-table-size=16M')
    Invoke-Owned $mysql ($common + @('--initialize-insecure', '--console')) 'mysql-init'
    $mysqlProcess = Start-Owned $mysql ($common + @('--bind-address=127.0.0.1', "--port=$mysqlPort", '--console')) 'mysql'
    $fixture = Quote-Path (Join-Path $PSScriptRoot 'fixture.php')
    Invoke-Owned $php @($fixture, 'prepare') 'prepare'
    Invoke-Owned $php @((Quote-Path (Join-Path $backend 'migrations/run.php'))) 'migrations'
    Invoke-Owned $php @($fixture, 'provision') 'provision'
    $identity = Get-Content -LiteralPath (Join-Path $runRoot 'fixture.json') -Raw | ConvertFrom-Json
    Invoke-Owned $php @((Quote-Path (Join-Path $backend 'config/compile.php')), "--tenant=$($identity.tenant_id)", "--device=$($identity.device_id)") 'compile'
    Invoke-Owned $php @($fixture, 'export-policy') 'export-policy'
    $http = Start-Owned $php @('-S', "127.0.0.1:$httpPort", '-t', (Quote-Path (Join-Path $backend 'public')), (Quote-Path (Join-Path $backend 'public/index.php'))) 'http'
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        if ($http.HasExited) { throw 'PHP server exited during startup' }
        $socket = [Net.Sockets.TcpClient]::new()
        try { $socket.Connect('127.0.0.1', $httpPort); $ready = $true; break }
        catch { Start-Sleep -Milliseconds 100 }
        finally { $socket.Dispose() }
    }
    if (-not $ready) { throw 'PHP server startup timeout' }
    $clientArguments = @('run', (Quote-Path $runRoot), "$httpPort")
    if ($CorruptLoginSignature) { $clientArguments += '--corrupt-login' }
    Invoke-Owned $harness $clientArguments 'interop'
    Invoke-Owned $php @($fixture, 'verify') 'verify'
} catch {
    $failure = $_.Exception.Message
    Write-Output "FAIL $failure"
    $httpLog = Join-Path $runRoot 'http.err'
    if (Test-Path -LiteralPath $httpLog) {
        Get-Content -LiteralPath $httpLog | Select-String 'keeper request=' | ForEach-Object { $_.Line }
    }
} finally {
    if ($null -ne $mysqlProcess -and -not $mysqlProcess.HasExited) {
        try {
            Invoke-Owned $php @((Quote-Path (Join-Path $PSScriptRoot 'fixture.php')), 'shutdown') 'mysql-shutdown' 15
            $null = $mysqlProcess.WaitForExit(10000)
        }
        catch { Write-Output 'MySQL graceful shutdown unavailable; stopping owned process.' }
    }
    foreach ($p in $processes) {
        try {
            Stop-OwnedTree $p
        } catch { $cleanupErrors.Add($_.Exception.Message) }
    }
    if ($null -ne $dotnet) {
        # This CLI command has no MSBuild flags; every build above uses both required flags.
        try { Invoke-Owned $dotnet @('build-server', 'shutdown') 'build-server-shutdown' 30 }
        catch {
            $cleanupErrors.Add($_.Exception.Message)
            foreach ($p in $processes) { Stop-OwnedTree $p }
        }
    }
    foreach ($name in $savedEnv.Keys) { [Environment]::SetEnvironmentVariable($name, $savedEnv[$name]) }
    try {
        $resolved = [IO.Path]::GetFullPath($runRoot)
        $allowed = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '.runs')) + [IO.Path]::DirectorySeparatorChar
        if (-not $resolved.StartsWith($allowed, [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe cleanup path' }
        for ($attempt = 0; $attempt -lt 20 -and (Test-Path -LiteralPath $resolved); $attempt++) {
            try { Remove-Item -LiteralPath $resolved -Recurse -Force }
            catch { if ($attempt -eq 19) { throw }; Start-Sleep -Milliseconds 250 }
        }
        if (Test-Path -LiteralPath $resolved) { throw 'Temporary datadir remains' }
    } catch { $cleanupErrors.Add($_.Exception.Message) }
    $alive = @($processes | Where-Object { -not $_.HasExited }).Count
    if ($alive -ne 0) { $cleanupErrors.Add("$alive owned processes remain") }
    if ($cleanupErrors.Count -eq 0) { Write-Output 'PASS cleanup: 0 temporary php/dotnet/mysqld/harness processes; 0 temporary datadirs' }
    else { foreach ($errorText in $cleanupErrors) { Write-Output "FAIL cleanup: $errorText" } }
}
if ($null -ne $failure -or $cleanupErrors.Count -ne 0) { Write-Output 'INTEGRATION FAIL'; exit 1 }
Write-Output 'INTEGRATION PASS'
exit 0
