param([int]$MySqlPort = 13384, [int]$HttpPort = 18084, [switch]$DebugResponses, [switch]$ActivityOnly, [string]$MariaDbDirectory = '')
$ErrorActionPreference = 'Stop'
$backend = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$tempRoot = Join-Path $backend ('.validation/mysql-' + [guid]::NewGuid().ToString('N'))
$mysqlProcess = $null
$httpProcess = $null
$savedEnv = @{}
$envNames = @('KEEPER_DB_DSN','KEEPER_DB_USER','KEEPER_DB_PASSWORD','KEEPER_DB_PASSWORD_FILE','KEEPER_ORIGIN','KEEPER_ALLOW_HTTP_LOCAL','KEEPER_RESPONSE_KEY','KEEPER_RESPONSE_KEY_ID','KEEPER_RATE_DIR','KEEPER_DEVICE_RATE','KEEPER_DEVICE_BURST','KEEPER_TENANT_FLEET','KEEPER_BOOTSTRAP_RATE','KEEPER_BOOTSTRAP_BURST','KEEPER_CHALLENGE_OUTSTANDING','KEEPER_TEST_ALLOW_FIXTURES','KEEPER_RELEASE_KEYS_FILE')
foreach ($name in $envNames) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name) }
$savedEnv['KEEPER_DEBUG'] = [Environment]::GetEnvironmentVariable('KEEPER_DEBUG')
$envNames += 'KEEPER_DEBUG'
$envNames += 'KEEPER_TEST_ACTIVITY_ONLY'
$savedEnv['KEEPER_TEST_ACTIVITY_ONLY'] = [Environment]::GetEnvironmentVariable('KEEPER_TEST_ACTIVITY_ONLY')
$envNames += @('PHP_INI_SCAN_DIR','KEEPER_ESCROW_KEY_FILE')
$savedEnv['PHP_INI_SCAN_DIR'] = [Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR')
$savedEnv['KEEPER_ESCROW_KEY_FILE'] = [Environment]::GetEnvironmentVariable('KEEPER_ESCROW_KEY_FILE')
foreach ($name in @('KEEPER_ADMIN_RATE','KEEPER_ADMIN_BURST','KEEPER_ADMIN_TENANT_RATE','KEEPER_ADMIN_TENANT_BURST')) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name); $envNames += $name }
foreach ($name in @('KEEPER_EXTERNAL_RATE','KEEPER_EXTERNAL_BURST','KEEPER_EXTERNAL_TENANT_RATE','KEEPER_EXTERNAL_TENANT_BURST','KEEPER_OAUTH_RATE','KEEPER_OAUTH_BURST')) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name); $envNames += $name }
try {
    $null = New-Item -ItemType Directory -Path $tempRoot -Force
    $datadir = Join-Path $tempRoot 'data'
    $null = New-Item -ItemType Directory -Path $datadir
    $mysql = if ($MariaDbDirectory -eq '') { (Get-Command mysqld).Source } else { Join-Path (Resolve-Path -LiteralPath $MariaDbDirectory).Path 'bin/mysqld.exe' }
    $php = (Get-Command php).Source
    $iniDir = Join-Path $tempRoot 'php-ini'
    $null = New-Item -ItemType Directory -Path $iniDir
    $sodiumEnabled = & $php -r 'echo extension_loaded(''sodium'') ? 1 : 0;'
    if ($sodiumEnabled -ne '1') { Set-Content -LiteralPath (Join-Path $iniDir 'sodium.ini') -Value 'extension=sodium' -Encoding ascii }
    $env:PHP_INI_SCAN_DIR = $iniDir
    $env:KEEPER_ESCROW_KEY_FILE = Join-Path $tempRoot 'escrow.key'
    & $php (Join-Path $PSScriptRoot 'transport.php')
    if ($LASTEXITCODE -ne 0) { throw 'Transport checks failed' }
    $common = @('--no-defaults', ('--datadir="' + $datadir + '"'), ('--tmpdir="' + $tempRoot + '"'), '--skip-log-bin', '--innodb-buffer-pool-size=64M')
    if ($MariaDbDirectory -eq '') {
        $common += '--mysqlx=0'
        $init = Start-Process -FilePath $mysql -ArgumentList ($common + @('--initialize-insecure', '--console')) -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput (Join-Path $tempRoot 'init.out') -RedirectStandardError (Join-Path $tempRoot 'init.err')
    } else {
        $installer = Join-Path $MariaDbDirectory 'bin/mysql_install_db.exe'
        $init = Start-Process -FilePath $installer -ArgumentList @(('--datadir="' + $datadir + '"'),('--port=' + $MySqlPort)) -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput (Join-Path $tempRoot 'init.out') -RedirectStandardError (Join-Path $tempRoot 'init.err')
    }
    if ($init.ExitCode -ne 0) { throw 'MySQL initialization failed; isolated harness log available until cleanup.' }
    $mysqlProcess = Start-Process -FilePath $mysql -ArgumentList ($common + @('--bind-address=127.0.0.1', ('--port=' + $MySqlPort), '--console')) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $tempRoot 'mysql.out') -RedirectStandardError (Join-Path $tempRoot 'mysql.err')
    $env:KEEPER_DB_DSN = "mysql:host=127.0.0.1;port=$MySqlPort;dbname=keeper_v4_agent_test;charset=utf8mb4"
    $env:KEEPER_DB_USER = 'root'
    $env:KEEPER_DB_PASSWORD = ''
    $env:KEEPER_DB_PASSWORD_FILE = ''
    $env:KEEPER_ORIGIN = "http://127.0.0.1:$HttpPort"
    $env:KEEPER_ALLOW_HTTP_LOCAL = '1'
    $key = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($key); $rng.Dispose()
    $env:KEEPER_RESPONSE_KEY = [Convert]::ToBase64String($key)
    $env:KEEPER_RESPONSE_KEY_ID = 'smoke-1'
    $env:KEEPER_RATE_DIR = Join-Path $tempRoot 'rate'
    $env:KEEPER_RELEASE_KEYS_FILE = Join-Path $tempRoot 'release-keys.json'
    $env:KEEPER_DEVICE_RATE = '12000'
    $env:KEEPER_ADMIN_RATE = '12000'
    $env:KEEPER_ADMIN_BURST = '1000'
    $env:KEEPER_ADMIN_TENANT_RATE = '12000'
    $env:KEEPER_ADMIN_TENANT_BURST = '1000'
    $env:KEEPER_DEVICE_BURST = '1000'
    $env:KEEPER_TENANT_FLEET = '10000'
    $env:KEEPER_BOOTSTRAP_RATE = '6000'
    $env:KEEPER_BOOTSTRAP_BURST = '100'
    $env:KEEPER_CHALLENGE_OUTSTANDING = '3'
    $env:KEEPER_EXTERNAL_RATE = '1'
    $env:KEEPER_EXTERNAL_BURST = '150'
    $env:KEEPER_EXTERNAL_TENANT_RATE = '1'
    $env:KEEPER_EXTERNAL_TENANT_BURST = '500'
    $env:KEEPER_OAUTH_RATE = '10'
    $env:KEEPER_OAUTH_BURST = '30'
    $env:KEEPER_TEST_ALLOW_FIXTURES = '1'
    $env:KEEPER_DEBUG = if ($DebugResponses) { '1' } else { '0' }
    $env:KEEPER_TEST_ACTIVITY_ONLY = if ($ActivityOnly) { '1' } else { '0' }
    & $php (Join-Path $PSScriptRoot 'prepare.php')
    if ($LASTEXITCODE -ne 0) { throw 'Test database preparation failed' }
    & $php (Join-Path $backend 'migrations/run.php')
    if ($LASTEXITCODE -ne 0) { throw 'Migrations failed' }
    & $php (Join-Path $backend 'migrations/verify.php')
    if ($LASTEXITCODE -ne 0) { throw 'Migration and seed verification failed' }
    & $php (Join-Path $backend 'migrations/run.php')
    if ($LASTEXITCODE -ne 0) { throw 'Migration replay failed' }
    & $php (Join-Path $PSScriptRoot 'migration-collation.php')
    if ($LASTEXITCODE -ne 0) { throw 'Migration collation regression failed' }
    $httpProcess = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$HttpPort", '-t', ('"' + (Join-Path $backend 'public') + '"'), ('"' + (Join-Path $backend 'public/index.php') + '"')) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $tempRoot 'http.out') -RedirectStandardError (Join-Path $tempRoot 'http.err')
    Start-Sleep -Milliseconds 500
    & $php (Join-Path $PSScriptRoot 'smoke.php')
    if ($LASTEXITCODE -ne 0) {
        Get-Content -LiteralPath (Join-Path $tempRoot 'http.err') | Select-String 'keeper request=' | ForEach-Object { $_.Line }
        throw 'Agent smoke failed'
    }
} finally {
    if ($null -ne $mysqlProcess) {
        $env:KEEPER_TEST_DATADIR = $datadir
        & $php (Join-Path $PSScriptRoot 'shutdown.php')
        Remove-Item Env:KEEPER_TEST_DATADIR -ErrorAction SilentlyContinue
    }
    foreach ($process in @($httpProcess, $mysqlProcess)) {
        if ($null -ne $process) {
            $null = $process.WaitForExit(5000)
            if (-not $process.HasExited) { Stop-Process -Id $process.Id -Force; $process.WaitForExit() }
        }
    }
    foreach ($name in $envNames) { [Environment]::SetEnvironmentVariable($name, $savedEnv[$name]) }
    $resolved = [IO.Path]::GetFullPath($tempRoot)
    $allowed = [IO.Path]::GetFullPath((Join-Path $backend '.validation')) + [IO.Path]::DirectorySeparatorChar
    if (-not $resolved.StartsWith($allowed, [StringComparison]::OrdinalIgnoreCase)) { throw 'Unsafe harness cleanup path' }
    for ($attempt = 0; $attempt -lt 20 -and (Test-Path -LiteralPath $resolved); $attempt++) {
        try { Remove-Item -LiteralPath $resolved -Recurse -Force } catch { if ($attempt -eq 19) { throw }; Start-Sleep -Milliseconds 250 }
    }
    $validation = Join-Path $backend '.validation'
    if ((Test-Path -LiteralPath $validation) -and @(Get-ChildItem -LiteralPath $validation -Force).Count -eq 0) { Remove-Item -LiteralPath $validation -Force }
    Write-Output 'Isolated MySQL processes and datadir removed.'
}
