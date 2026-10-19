# Pruebas reales del tope de descargas simultaneas de GET /api/client/version.
#
#   powershell -ExecutionPolicy Bypass -File tests\update-gate-test.ps1
#
# Arranca un mysqld AISLADO (datadir en $env:TEMP, puerto libre, --initialize-insecure) y dos
# servidores php -S sobre public/index.php con su propio .env (KEEPER_ENV_FILE): uno con el tope
# (UPDATE_GATE_MAX=5, ventana 300 s) y otro con el tope desactivado (UPDATE_GATE_MAX=0).
# Nunca lee el Web/.env habitual ni toca otra base. Al terminar detiene SOLO los procesos que
# arranco (por PID) y borra el datadir.
param([string]$MySqlExe = '', [string]$PhpExe = '')
$ErrorActionPreference = 'Stop'

$web = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$tempRoot = Join-Path $env:TEMP ('k3-update-gate-' + [guid]::NewGuid().ToString('N'))
$mysqld = if ($MySqlExe -ne '') { $MySqlExe } else { (Get-Command mysqld).Source }
$php = if ($PhpExe -ne '') { $PhpExe } else { (Get-Command php).Source }
$processes = @()
$mysqlProcess = $null
$mysqlPort = 0
$exitCode = 1

# Variables que no deben heredarse hacia los php -S (Config::get cae a getenv)
$envNames = @('KEEPER_ENV_FILE','DB_HOST','DB_NAME','DB_USER','DB_PASS','UPDATE_GATE_MAX','UPDATE_GATE_WINDOW_SECONDS',
              'GATE_DB_PORT','GATE_DB_NAME','GATE_DB_USER','GATE_DB_PASS','GATE_URL_ON','GATE_URL_OFF')
$savedEnv = @{}
foreach ($name in $envNames) { $savedEnv[$name] = [Environment]::GetEnvironmentVariable($name); [Environment]::SetEnvironmentVariable($name, $null) }

function Get-FreePort {
    $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback, 0)
    $listener.Start(); $p = $listener.LocalEndpoint.Port; $listener.Stop(); return $p
}

function Wait-Port([int]$Port, [int]$Seconds) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    while ((Get-Date) -lt $deadline) {
        $client = New-Object Net.Sockets.TcpClient
        try { $client.Connect('127.0.0.1', $Port); return } catch { Start-Sleep -Milliseconds 300 } finally { $client.Dispose() }
    }
    throw "El puerto $Port no respondio en $Seconds s"
}

try {
    $datadir = Join-Path $tempRoot 'data'
    $null = New-Item -ItemType Directory -Path $datadir -Force

    # 1. php -l de los archivos tocados
    foreach ($f in @('src/Endpoints/ClientVersion.php', 'tests/update-gate-test.php')) {
        & $php -l (Join-Path $web $f)
        if ($LASTEXITCODE -ne 0) { throw "php -l fallo en $f" }
    }

    # 2. mysqld aislado
    $mysqlPort = Get-FreePort
    $common = @('--no-defaults', ('--datadir="' + $datadir + '"'), ('--tmpdir="' + $tempRoot + '"'), '--skip-log-bin', '--innodb-buffer-pool-size=64M', '--mysqlx=0')
    $init = Start-Process -FilePath $mysqld -ArgumentList ($common + @('--initialize-insecure', '--console')) -WindowStyle Hidden -PassThru -Wait `
        -RedirectStandardOutput (Join-Path $tempRoot 'init.out') -RedirectStandardError (Join-Path $tempRoot 'init.err')
    if ($init.ExitCode -ne 0) { Get-Content (Join-Path $tempRoot 'init.err') | Select-Object -Last 20; throw 'mysqld --initialize-insecure fallo' }
    $mysqlProcess = Start-Process -FilePath $mysqld -ArgumentList ($common + @('--bind-address=127.0.0.1', ('--port=' + $mysqlPort), '--console')) -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput (Join-Path $tempRoot 'mysql.out') -RedirectStandardError (Join-Path $tempRoot 'mysql.err')
    $processes += $mysqlProcess
    Write-Output "mysqld aislado PID $($mysqlProcess.Id) en 127.0.0.1:$mysqlPort (datadir $datadir)"
    Wait-Port $mysqlPort 60
    Start-Sleep -Milliseconds 500

    # 3. Dos servidores php -S con .env propio
    $dbName = 'keeper_gate_test'
    $dbUser = 'gate'
    $dbPass = 'gate-' + [guid]::NewGuid().ToString('N').Substring(0, 12)
    $servers = @{}
    foreach ($prof in @(@{ Name = 'ON'; Max = '5' }, @{ Name = 'OFF'; Max = '0' })) {
        $envFile = Join-Path $tempRoot ("gate-" + $prof.Name + ".env")
        # DB_HOST lleva el puerto: Db::createConnection arma "mysql:host={DB_HOST};dbname=..."
        @("APP_ENV=test", "APP_BASE_URL=", "API_PREFIX=/api", "DB_HOST=127.0.0.1;port=$mysqlPort", "DB_NAME=$dbName",
          "DB_USER=$dbUser", "DB_PASS=$dbPass", ("UPDATE_GATE_MAX=" + $prof.Max), "UPDATE_GATE_WINDOW_SECONDS=300") |
            Set-Content -LiteralPath $envFile -Encoding ascii
        $httpPort = Get-FreePort
        $env:KEEPER_ENV_FILE = $envFile
        $proc = Start-Process -FilePath $php -ArgumentList @('-S', "127.0.0.1:$httpPort", '-t', ('"' + (Join-Path $web 'public') + '"'), ('"' + (Join-Path $web 'public/index.php') + '"')) `
            -WorkingDirectory $web -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput (Join-Path $tempRoot ("http-" + $prof.Name + ".out")) -RedirectStandardError (Join-Path $tempRoot ("http-" + $prof.Name + ".err"))
        $processes += $proc
        $servers[$prof.Name] = "http://127.0.0.1:$httpPort"
        Write-Output ("php -S " + $prof.Name + " (UPDATE_GATE_MAX=" + $prof.Max + ") PID $($proc.Id) en " + $servers[$prof.Name])
        Wait-Port $httpPort 20
    }
    [Environment]::SetEnvironmentVariable('KEEPER_ENV_FILE', $null)

    # 4. Casos
    $env:GATE_DB_PORT = "$mysqlPort"; $env:GATE_DB_NAME = $dbName; $env:GATE_DB_USER = $dbUser; $env:GATE_DB_PASS = $dbPass
    $env:GATE_URL_ON = $servers['ON']; $env:GATE_URL_OFF = $servers['OFF']
    & $php (Join-Path $PSScriptRoot 'update-gate-test.php')
    $exitCode = $LASTEXITCODE

    Write-Output ''
    Write-Output 'error_log del servidor con tope (fail-open esperado en el caso h):'
    foreach ($n in @('ON', 'OFF')) {
        foreach ($ext in @('out', 'err')) {
            $log = Join-Path $tempRoot ("http-$n.$ext")
            if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log | Select-String 'ClientVersion|Fatal|Warning' | ForEach-Object { "  [$n] " + $_.Line } }
        }
    }
} finally {
    # mysqld 8.4 en Windows corre como monitor + hijo: primero SHUTDOWN ordenado (solo si @@datadir es el nuestro)
    if ($null -ne $mysqlProcess -and -not $mysqlProcess.HasExited) {
        $shutdownPhp = Join-Path $tempRoot 'shutdown.php'
        Set-Content -LiteralPath $shutdownPhp -Encoding ascii -Value @'
<?php
$d = new PDO('mysql:host=127.0.0.1;port=' . getenv('GATE_DB_PORT'), 'root', '');
$n = fn($p) => strtolower(rtrim(str_replace('\\', '/', (string)$p), '/'));
if ($n($d->query('SELECT @@datadir')->fetchColumn()) === $n(getenv('GATE_DATADIR'))) { $d->exec('SHUTDOWN'); }
'@
        $env:GATE_DATADIR = $datadir; $env:GATE_DB_PORT = "$mysqlPort"
        try { & $php $shutdownPhp } catch { }
        Remove-Item Env:GATE_DATADIR -ErrorAction SilentlyContinue
        $null = $mysqlProcess.WaitForExit(20000)
    }
    # Lo que siga vivo: hijos (por PID padre) y luego el propio proceso, siempre por PID propio
    foreach ($p in $processes) {
        if ($null -eq $p) { continue }
        Get-CimInstance Win32_Process -Filter "ParentProcessId = $($p.Id)" -ErrorAction SilentlyContinue |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        if (-not $p.HasExited) { Stop-Process -Id $p.Id -Force; $null = $p.WaitForExit(10000) }
    }
    foreach ($name in $envNames) { [Environment]::SetEnvironmentVariable($name, $savedEnv[$name]) }
    $resolved = [IO.Path]::GetFullPath($tempRoot)
    $allowed = [IO.Path]::GetFullPath($env:TEMP).TrimEnd('\') + '\k3-update-gate-'
    if (-not $resolved.StartsWith($allowed, [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta de limpieza insegura' }
    for ($attempt = 0; $attempt -lt 60 -and (Test-Path -LiteralPath $resolved); $attempt++) {
        try { Remove-Item -LiteralPath $resolved -Recurse -Force } catch { if ($attempt -eq 59) { throw }; Start-Sleep -Milliseconds 500 }
    }
    Write-Output "Procesos propios detenidos ($(@($processes | ForEach-Object { $_.Id }) -join ', ')) y datadir borrado."
}
exit $exitCode
