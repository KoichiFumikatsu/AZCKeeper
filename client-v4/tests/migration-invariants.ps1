$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$root = Split-Path $PSScriptRoot -Parent
foreach ($file in Get-ChildItem (Join-Path $root 'installer') -Filter *.ps1 -Recurse) {
    $tokens = $null; $errors = $null
    [Management.Automation.Language.Parser]::ParseFile($file.FullName, [ref]$tokens, [ref]$errors) | Out-Null
    if ($errors.Count) { throw "Invalid PowerShell: $($file.Name)" }
}
Add-Type -Path (Join-Path $root 'installer/Keeper.Installer/Migration.Native.cs')
. (Join-Path $root 'installer/Keeper.Installer/Migration.Journal.ps1')
function Assert($Condition, [string]$Message) { if (!$Condition) { throw $Message } }
function Must-Fail([scriptblock]$Action) { $failed = $false; try { & $Action } catch { $failed = $true }; Assert $failed 'Expected a fail-closed decision' }
$journal = New-MigrationJournal
$journal.installed = $true
Must-Fail { Add-MigrationTransition $journal 'degradado' }
Must-Fail { Add-MigrationTransition $journal 'inventado' }
Add-MigrationTransition $journal 'instalado'
$report = $journal.pending_reports[0] | ConvertTo-Json -Compress
Add-MigrationTransition $journal 'instalado'
Assert ($journal.revision -eq 1 -and $journal.pending_reports.Count -eq 1) 'Duplicate transition changed revision'
Assert (($journal.pending_reports[0] | ConvertTo-Json -Compress) -eq $report) 'Retry changed the CAS payload'
Add-MigrationTransition $journal 'enrolado'
Must-Fail { Add-MigrationTransition $journal 'escrow_ok' }
$journal.credential_tested = $true
Must-Fail { Add-MigrationTransition $journal 'escrow_ok' }
$journal.escrow_verified = $true; $journal.recovery_sid = 'S-1-5-21-1-2-3-1001'; $journal.escrow = @{revision=1}
$journal.work_sids = @('S-1-5-21-1-2-3-1002')
Add-MigrationTransition $journal 'escrow_ok'
Add-MigrationTransition $journal 'degradado'
$journal.demoted = $journal.work_sids
Add-MigrationTransition $journal 'pendiente_reinicio'
Must-Fail { Add-MigrationTransition $journal 'completo' }
$journal.verified_sids = $journal.work_sids
Add-MigrationTransition $journal 'completo'
Assert $journal.pending_reports[-1].standard_user_verified 'Missing token verification report'
$path = Join-Path $env:TEMP ('keeper-journal-test-' + [guid]::NewGuid() + '.json')
try {
    $journal | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $path
    $restored = Read-MigrationJournal $path
    Assert ($restored.phase -eq 'completo' -and $restored.revision -eq 6) 'Resume lost progress'
    Assert (($restored.pending_reports[0] | ConvertTo-Json -Compress) -eq $report) 'Resume changed pending report'
    $restored.escrow_verified = $false
    $restored | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $path
    Must-Fail { Read-MigrationJournal $path }
    '{"installed":true,"recovery_sid":null,"demoted":[]}' | Set-Content -LiteralPath $path
    Must-Fail { Read-MigrationJournal $path }
    '{invalid' | Set-Content -LiteralPath $path
    Must-Fail { Read-MigrationJournal $path }
} finally { Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue }
$failed = New-MigrationJournal
Add-MigrationTransition $failed 'instalado'
Add-MigrationTransition $failed 'excepcion' 'credential_logon_failed'
Assert ($failed.safe_phase -eq 'instalado') 'Exception lost last safe phase'
Must-Fail { Add-MigrationTransition $failed 'enrolado' }
Assert ($failed.pending_reports[-1].phase -eq 'excepcion') 'Exception was not queued'
$recovery = 'S-1-5-21-1-2-3-1001'; $work = 'S-1-5-21-1-2-3-1002'; $builtin = 'S-1-5-21-1-2-3-500'
$accounts = @([pscustomobject]@{Sid=$builtin;Enabled=$false;BlankPassword='not_tested_disabled'})
Assert (!(Get-AdministratorAuditFailure $accounts @($builtin,$recovery,$work) $recovery @($work))) 'Disabled built-in blocks safe fleet'
$accounts[0].Enabled = $true
Assert ((Get-AdministratorAuditFailure $accounts @($builtin,$recovery,$work) $recovery @($work)) -eq 'builtin_administrator_enabled') 'Enabled RID 500 was allowed'
$accounts[0].Enabled = $false
Assert ((Get-AdministratorAuditFailure $accounts @($recovery,$work,'S-1-5-21-1-2-3-1999') $recovery @($work)) -eq 'unexpected_administrator') 'Undeclared administrator was allowed'
$accounts += [pscustomobject]@{Sid=$work;Enabled=$true;BlankPassword='confirmed'}
Assert ((Get-AdministratorAuditFailure $accounts @($recovery,$work) $recovery @($work)) -eq 'blank_password') 'Blank password was allowed'
$secret = [Keeper.Migration.Native]::NewPassword()
try { Assert ($secret.Length -eq 32 -and $secret.IsReadOnly()) 'Recovery password is not protected/32 characters' } finally { $secret.Dispose() }
$plan = & (Join-Path $root 'installer/Keeper.Installer/Bootstrap.ps1') -Phase Plan
Assert (!$plan.Mutation) 'Plan should be read-only'
& (Join-Path $root 'installer/Keeper.Installer/Bootstrap.ps1') -Phase All -WhatIf
Write-Output 'Migration journal, audit, parser, password and dry-run invariants passed.'
