param([int]$MySqlPort = 13386)
$ErrorActionPreference = 'Stop'
$tempRoot = Join-Path $PSScriptRoot ('.backfill-test-' + [guid]::NewGuid().ToString('N'))
$server = $null
try {
    $null = New-Item -ItemType Directory -Path $tempRoot
    $data = Join-Path $tempRoot 'data'
    $null = New-Item -ItemType Directory -Path $data
    $mysql = (Get-Command mysqld).Source
    $common = @('--no-defaults', ('--datadir="' + $data + '"'), ('--tmpdir="' + $tempRoot + '"'), '--skip-log-bin', '--mysqlx=0', '--innodb-buffer-pool-size=64M')
    $init = Start-Process -FilePath $mysql -ArgumentList ($common + @('--initialize-insecure', '--console')) -WindowStyle Hidden -PassThru -Wait -RedirectStandardOutput (Join-Path $tempRoot 'init.out') -RedirectStandardError (Join-Path $tempRoot 'init.err')
    if ($init.ExitCode -ne 0) { throw 'No se pudo inicializar MySQL aislado.' }
    $server = Start-Process -FilePath $mysql -ArgumentList ($common + @('--bind-address=127.0.0.1', ('--port=' + $MySqlPort), '--console')) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $tempRoot 'mysql.out') -RedirectStandardError (Join-Path $tempRoot 'mysql.err')
    & php (Join-Path $PSScriptRoot 'test-k3-backfill.php') $MySqlPort $tempRoot
    if ($LASTEXITCODE -ne 0) { throw 'Fallaron las pruebas de backfill.' }
} finally {
    if ($null -ne $server) {
        & php (Join-Path $PSScriptRoot 'test-k3-backfill.php') $MySqlPort $tempRoot --shutdown
        $null = $server.WaitForExit(5000)
    }
    if ($null -ne $server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force
        $server.WaitForExit()
    }
    $resolved = [IO.Path]::GetFullPath($tempRoot)
    $allowed = [IO.Path]::GetFullPath($PSScriptRoot) + [IO.Path]::DirectorySeparatorChar
    if (-not $resolved.StartsWith($allowed, [StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta de limpieza fuera de tools.' }
    for ($attempt = 0; $attempt -lt 20 -and (Test-Path -LiteralPath $resolved); $attempt++) {
        try { Remove-Item -LiteralPath $resolved -Recurse -Force }
        catch { if ($attempt -eq 19) { throw }; Start-Sleep -Milliseconds 250 }
    }
    Write-Output 'MySQL aislado y temporales eliminados.'
}
