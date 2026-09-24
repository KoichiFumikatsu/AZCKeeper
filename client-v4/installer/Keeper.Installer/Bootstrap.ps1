[CmdletBinding(SupportsShouldProcess)]
param(
    [ValidateSet('Plan', 'All', 'Install', 'ProvisionRecovery', 'Demote', 'Logoff', 'Verify', 'Report')][string]$Phase = 'Plan',
    [string]$MsiPath,
    [string]$PublisherThumbprint,
    [string]$PublisherCertificatePath,
    [string]$PublisherCertificateSha256,
    # libsodium no lleva firma Authenticode: se ancla por SHA-256. El valor viaja en deployment.json,
    # cubierto por el manifiesto firmado del paquete puente.
    [ValidatePattern('^[0-9a-fA-F]{64}$')][string]$LibsodiumSha256,
    [System.Security.SecureString]$RecoveryPassword,
    [string[]]$WorkUserSids = @(),
    [guid]$TenantId,
    [guid]$DeviceId,
    [ValidateRange(30, 600)][int]$WarningSeconds = 120,
    [scriptblock]$ControlPlane,
    [scriptblock]$EnrollDevice,
    [scriptblock]$ApplyBaseline,
    [switch]$EnrollmentConfirmed,
    [switch]$EscrowRecoveryConfirmed,
    [switch]$BaselineAndSessionConfirmed
)
$ErrorActionPreference = 'Stop'
if ($Phase -eq 'Plan') {
    [pscustomobject]@{
        Steps = 'Verificar puente -> UAC unico -> MSI/SYSTEM -> enrollment PoP -> azcadmin -> logon NETWORK -> escrow/recuperacion -> baseline/Session -> auditar admins -> degradar -> logoff -> token estandar -> ocultar -> completo'
        Mutation = $false
        ControlPlaneRequired = 'Adaptador IT firmado; las rutas de migracion requieren adminSession/CSRF. Nunca entregar cookies administrativas al equipo.'
    }
    return
}
# No effects, including journal or reports, under WhatIf.
if (!$PSCmdlet.ShouldProcess($env:COMPUTERNAME, "Migracion: $Phase")) { return }
. (Join-Path $PSScriptRoot 'Migration.Journal.ps1')
if (!('Keeper.Migration.Native' -as [type])) { Add-Type -Path (Join-Path $PSScriptRoot 'Migration.Native.cs') }
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
try {
    if (!(New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { throw 'elevation_required' }
    if ($Phase -notin @('Install', 'Report') -and !$identity.IsSystem) { throw 'system_worker_required' }
} finally { $identity.Dispose() }
$adminGroup = Get-LocalGroup -SID 'S-1-5-32-544'
$usersGroup = Get-LocalGroup -SID 'S-1-5-32-545'
$stateDirectory = Join-Path $env:ProgramData 'AZCKeeper\v4'
$journalPath = Join-Path $stateDirectory 'bootstrap-journal.json'
New-Item -ItemType Directory -Path $stateDirectory -Force | Out-Null
if ((Get-Item -LiteralPath $stateDirectory).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'state_reparse_point' }
$acl = New-Object Security.AccessControl.DirectorySecurity
$acl.SetOwner((New-Object Security.Principal.SecurityIdentifier('S-1-5-32-544')))
$acl.SetAccessRuleProtection($true, $false)
foreach ($sidText in @('S-1-5-18', 'S-1-5-32-544')) {
    $sid = New-Object Security.Principal.SecurityIdentifier($sidText)
    $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')))
}
Set-Acl -LiteralPath $stateDirectory -AclObject $acl
# A machine-wide lease serializes explicit runs and the resume task.
$lease = [IO.File]::Open((Join-Path $stateDirectory 'bootstrap.lock'), 'OpenOrCreate', 'ReadWrite', 'None')
$journal = $null
function Save-Journal($state) {
    $temporary = $journalPath + '.tmp'
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes(($state | ConvertTo-Json -Depth 12))
        $stream = [IO.File]::Open($temporary, 'Create', 'Write', 'None')
        try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) } finally { $stream.Dispose() }
        if (Test-Path -LiteralPath $journalPath) { [IO.File]::Replace($temporary, $journalPath, $null) }
        else { [IO.File]::Move($temporary, $journalPath) }
    } finally { if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary -Force } }
}
function Request-Control([string]$Method, [string]$Path, $Body) {
    if (!$ControlPlane) { throw 'control_plane_unavailable' }
    # Adapter forwards the exact API contract through IT. No administrative cookie is accepted here.
    & $ControlPlane $Method $Path $Body
}
function Flush-Reports {
    while (@($journal.pending_reports).Count -gt 0) {
        $report = $journal.pending_reports[0]
        $ack = Request-Control 'PUT' "/v1/devices/$DeviceId/migration" $report
        if ($ack.device_id -ne $DeviceId.ToString() -or $ack.phase -ne $report.phase -or $ack.revision -ne $report.revision) { throw 'migration_ack_mismatch' }
        if ($report.phase -in @('escrow_ok', 'degradado', 'pendiente_reinicio', 'completo') -and $ack.escrow_revision -ne $journal.escrow.revision) { throw 'migration_escrow_revision_mismatch' }
        $journal.pending_reports = @($journal.pending_reports | Select-Object -Skip 1)
        Save-Journal $journal
    }
}
function Transition([string]$Next) {
    Add-MigrationTransition $journal $Next
    Save-Journal $journal
    Flush-Reports
}
function Assert-Recovery {
    if (!$journal.recovery_sid -or !$journal.credential_tested -or !$journal.escrow_verified -or !$journal.escrow) { throw 'recovery_not_verified' }
    $recovery = Get-LocalUser -SID $journal.recovery_sid
    if ($recovery.Name -ne 'azcadmin' -or !$recovery.Enabled -or !(Get-LocalGroupMember $adminGroup | Where-Object { $_.SID.Value -eq $journal.recovery_sid })) { throw 'recovery_account_unavailable' }
    $verification = Request-Control 'POST' "/v1/devices/$DeviceId/escrow/verify" @{ revision = $journal.escrow.revision; sha256 = $journal.escrow.sha256 }
    if (!$verification.recoverable -or $verification.revision -ne $journal.escrow.revision -or $verification.sha256 -ne $journal.escrow.sha256) { throw 'escrow_verification_failed' }
    # Deliberadamente NO se llama a /escrow/recover desde el equipo.
    # 'verify' ya abre el sobre en el servidor y hace sodium_memzero de la contrasena: prueba que la
    # boveda puede recuperar el secreto sin que el texto plano salga de alli. 'recover' SI devuelve la
    # contrasena en claro y esta protegido por 'migracion.recuperar', un permiso no delegable pensado
    # como operacion de emergencia auditada de IT. Llamarlo desde cada equipo la convertiria en rutina
    # de toda la flota y devolveria la credencial de administrador a la maquina que se esta endureciendo.
    # La credencial ya se probo localmente con Probe() en el momento de crearla (paso 7).
}
function Audit-Administrators {
    $audit = foreach ($account in Get-LocalUser) {
        $blank = 'not_tested_disabled'
        if ($account.Enabled) {
            $stamp = if ($account.PasswordLastSet) { $account.PasswordLastSet.ToUniversalTime().ToString('o') } else { 'unset' }
            $previous = @($journal.password_audits | Where-Object { $_.sid -eq $account.SID.Value -and $_.password_last_set -eq $stamp })
            if ($previous.Count) { $blank = $previous[-1].result }
            elseif ($account.SID.Value -eq $journal.recovery_sid) { $blank = 'credential_verified_nonblank' }
            else {
                # Journal before the attempt: repeated task runs must not lock users out by guessing again.
                $probe = [pscustomobject]@{ sid = $account.SID.Value; password_last_set = $stamp; result = 'indeterminate' }
                $journal.password_audits += $probe
                Save-Journal $journal
                $empty = New-Object Security.SecureString
                try { $result = [Keeper.Migration.Native]::Probe($account.Name, $empty) } finally { $empty.Dispose() }
                # 1326 is a negative credential test. Restrictions/lockout cannot prove absence of a blank password.
                $blank = if ($result -eq 0) { 'confirmed' } elseif ($result -eq 1326) { 'not_detected' } else { 'indeterminate' }
                $probe.result = $blank
                Save-Journal $journal
            }
        }
        [pscustomobject]@{ Sid = $account.SID.Value; Enabled = $account.Enabled; BlankPassword = $blank }
    }
    $members = @(Get-LocalGroupMember $adminGroup | ForEach-Object { $_.SID.Value })
    $failure = Get-AdministratorAuditFailure $audit $members $journal.recovery_sid $journal.work_sids
    if ($failure) { throw $failure }
    # Do not interpret a policy-denied NETWORK logon as proof that a privileged password is nonblank.
    if (@($audit | Where-Object { $_.Enabled -and $_.BlankPassword -eq 'indeterminate' -and $_.Sid -in $members }).Count) { throw 'admin_password_audit_indeterminate' }
}
function Install-Keeper {
    if ($journal.installed) {
        if ((Get-Service -Name 'AZCKeeper v4').Status -ne 'Running') { throw 'service_unavailable' }
        if (!$journal.safe_phase) { Transition 'instalado' }
        return
    }
    $package = (Resolve-Path -LiteralPath $MsiPath).Path
    if ($PublisherCertificatePath) {
        $cert = New-Object Security.Cryptography.X509Certificates.X509Certificate2((Resolve-Path -LiteralPath $PublisherCertificatePath).Path)
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $hash = ([BitConverter]::ToString($sha.ComputeHash($cert.RawData))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
        if (!$PublisherCertificateSha256 -or $hash -ne $PublisherCertificateSha256 -or $cert.Thumbprint -ne $PublisherThumbprint) { throw 'publisher_pin_mismatch' }
        # Pins originate in the already verified bridge, never in the downloaded certificate itself.
        foreach ($storeName in @('Root', 'TrustedPublisher')) {
            $store = New-Object Security.Cryptography.X509Certificates.X509Store($storeName, 'LocalMachine')
            try { $store.Open('ReadWrite'); $store.Add($cert) } finally { $store.Close() }
        }
        $cert.Dispose()
    }
    $signature = Get-AuthenticodeSignature -LiteralPath $package
    if (!$PublisherThumbprint -or $signature.Status -ne 'Valid' -or $signature.SignerCertificate.Thumbprint -ne $PublisherThumbprint) { throw 'msi_signature_invalid' }
    $msi = Start-Process -FilePath "$env:SystemRoot\System32\msiexec.exe" -ArgumentList @('/i', ('"' + $package + '"'), '/qn', '/norestart') -WindowStyle Hidden -Wait -PassThru
    if ($msi.ExitCode -notin @(0, 3010)) { throw 'msi_install_failed' }
    & "$env:SystemRoot\System32\sc.exe" sdset 'AZCKeeper v4' 'D:(A;;CCDCLCSWRPWPDTLOCRSDRCWDWO;;;SY)(A;;CCDCLCSWRPWPDTLOCRSDRCWDWO;;;BA)(A;;CCLCSWLOCRRC;;;BU)' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'service_acl_failed' }
    Start-Service -Name 'AZCKeeper v4'
    if ((Get-Service -Name 'AZCKeeper v4').Status -ne 'Running') { throw 'service_unavailable' }
    $journal.installed = $true
    Save-Journal $journal
    Transition 'instalado'
}
function Provision-Recovery {
    if ($journal.safe_phase -eq 'instalado') {
        if (!$EnrollDevice) { throw 'enrollment_adapter_required' }
        $enrollment = & $EnrollDevice $TenantId $DeviceId
        if (!$enrollment.proof_verified -or $enrollment.device_id -ne $DeviceId.ToString() -or $enrollment.tenant_id -ne $TenantId.ToString()) { throw 'enrollment_not_verified' }
        Transition 'enrolado'
    }
    if ($journal.safe_phase -ne 'enrolado') { return }
    $secret = $null
    try {
        if (!$journal.recovery_sid) {
            if (Get-LocalUser -Name 'azcadmin' -ErrorAction SilentlyContinue) { throw 'unowned_recovery_account' }
            if ($RecoveryPassword) { throw 'external_recovery_password_not_allowed' }
            $secret = [Keeper.Migration.Native]::NewPassword()
            if ($secret.Length -ne 32) { throw 'invalid_recovery_password' }
            $account = New-LocalUser -Name 'azcadmin' -Password $secret -Description 'Recuperacion IT AZCKeeper'
            $journal.recovery_sid = $account.SID.Value
            Save-Journal $journal
            Add-LocalGroupMember -Group $adminGroup -Member $account
            if (!$account.Enabled -or !(Get-LocalGroupMember $adminGroup | Where-Object { $_.SID.Value -eq $account.SID.Value })) { throw 'recovery_group_failed' }
            if ([Keeper.Migration.Native]::Probe('azcadmin', $secret) -ne 0) { throw 'credential_logon_failed' }
            $journal.credential_tested = $true
            $key = Request-Control 'GET' '/v1/migration/escrow-key' $null
            if ($key.algorithm -ne 'crypto_box_seal') { throw 'escrow_key_algorithm' }
            $publicKey = [Convert]::FromBase64String($key.public_key)
            $sha = [Security.Cryptography.SHA256]::Create()
            try { $keyHash = ([BitConverter]::ToString($sha.ComputeHash($publicKey))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
            if ($keyHash -ne $key.key_id) { throw 'escrow_key_mismatch' }
            if (!$LibsodiumSha256) { throw 'libsodium_hash_missing' }
            [Keeper.Migration.Native]::LoadSodium((Join-Path $PSScriptRoot 'libsodium.dll'), $LibsodiumSha256)
            $envelope = [Keeper.Migration.Native]::Seal($secret, $TenantId.ToString(), $DeviceId.ToString(), $account.SID.Value, 1, $publicKey)
            $sha = [Security.Cryptography.SHA256]::Create()
            try { $hash = ([BitConverter]::ToString($sha.ComputeHash([Convert]::FromBase64String($envelope)))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
            $journal.escrow = [pscustomobject]@{ tenant_id = $TenantId.ToString(); device_id = $DeviceId.ToString(); account_sid = $account.SID.Value; revision = 1; key_id = $key.key_id; envelope = $envelope; sha256 = $hash }
            Save-Journal $journal
        }
        # A crash before the encrypted envelope was journaled requires assistance, never password rotation.
        if (!$journal.credential_tested -or !$journal.escrow) { throw 'incomplete_recovery_requires_assistance' }
        $e = $journal.escrow
        $ack = Request-Control 'PUT' "/v1/devices/$DeviceId/escrow" @{ tenant_id = $e.tenant_id; device_id = $e.device_id; account_sid = $e.account_sid; revision = $e.revision; key_id = $e.key_id; envelope = $e.envelope }
        if (!$ack.persisted -or !$ack.escrow_id -or !$ack.persisted_at -or $ack.revision -ne $e.revision -or $ack.sha256 -ne $e.sha256) { throw 'escrow_persistence_not_confirmed' }
        $journal.escrow_verified = $true
        Assert-Recovery
        Save-Journal $journal
        Transition 'escrow_ok'
    } finally { if ($secret) { $secret.Dispose() } }
}
function Demote-Users {
    if ($journal.safe_phase -ne 'escrow_ok') { throw 'demotion_out_of_order' }
    Assert-Recovery
    if (!$ApplyBaseline) { throw 'baseline_adapter_required' }
    $baseline = & $ApplyBaseline $DeviceId $journal.work_sids
    if (!$baseline.applied -or !$baseline.session_ready -or $baseline.application_control_mode -ne 'audit') { throw 'baseline_or_session_not_ready' }
    if ((Get-Service 'AZCKeeper v4').Status -ne 'Running') { throw 'service_unavailable' }
    Audit-Administrators
    $accounts = foreach ($sidText in $journal.work_sids) {
        if ($sidText -eq $journal.recovery_sid -or $sidText -match '-(500|501|503|504)$') { throw 'protected_account' }
        Get-LocalUser -SID $sidText
    }
    foreach ($account in $accounts) {
        if (!@($journal.prior_membership | Where-Object { $_.sid -eq $account.SID.Value }).Count) {
            $journal.prior_membership += [pscustomobject]@{ sid = $account.SID.Value; administrator = [bool](Get-LocalGroupMember $adminGroup | Where-Object { $_.SID -eq $account.SID }); users = [bool](Get-LocalGroupMember $usersGroup | Where-Object { $_.SID -eq $account.SID }) }
            Save-Journal $journal
        }
        if (!(Get-LocalGroupMember $usersGroup | Where-Object { $_.SID -eq $account.SID })) { Add-LocalGroupMember $usersGroup -Member $account }
        if (Get-LocalGroupMember $adminGroup | Where-Object { $_.SID -eq $account.SID }) { Remove-LocalGroupMember $adminGroup -Member $account }
        if (Get-LocalGroupMember $adminGroup | Where-Object { $_.SID -eq $account.SID }) { throw 'demotion_failed' }
        $journal.demoted = @($journal.demoted + $account.SID.Value | Select-Object -Unique)
        Save-Journal $journal
    }
    Transition 'degradado'
}
function Close-WorkSessions {
    if ($journal.safe_phase -eq 'degradado') { Transition 'pendiente_reinicio' }
    if ($journal.safe_phase -ne 'pendiente_reinicio') { throw 'logoff_out_of_order' }
    $sessions = @([Keeper.Migration.Native]::Sessions() | Where-Object { $_.Sid -in $journal.work_sids -and !$_.Standard })
    $journal.logoff_started = $true
    Save-Journal $journal
    foreach ($session in $sessions) { [Keeper.Migration.Native]::Warn($session.Id, $WarningSeconds) }
    if ($sessions.Count) { Start-Sleep -Seconds $WarningSeconds }
    foreach ($session in $sessions) {
        $current = @([Keeper.Migration.Native]::Sessions() | Where-Object { $_.Id -eq $session.Id -and $_.LogonId -eq $session.LogonId })
        if ($current.Count) { [Keeper.Migration.Native]::Logoff($session.Id) }
        $journal.logged_off = @($journal.logged_off + $session.LogonId | Select-Object -Unique)
        Save-Journal $journal
    }
}
function Verify-Users {
    if ($journal.safe_phase -ne 'pendiente_reinicio' -or !$journal.logoff_started) { throw 'verification_out_of_order' }
    Audit-Administrators
    if (@(Get-LocalGroupMember $adminGroup | Where-Object { $_.SID.Value -in $journal.work_sids }).Count) { throw 'work_user_readmitted_to_administrators' }
    $sessions = @([Keeper.Migration.Native]::Sessions() | Where-Object { $_.Sid -in $journal.work_sids })
    if (@($sessions | Where-Object { !$_.Standard }).Count) { throw 'administrator_token_survived' }
    foreach ($session in $sessions) {
        $journal.verified_sids = @($journal.verified_sids + $session.Sid | Select-Object -Unique)
    }
    Save-Journal $journal
    if (@($journal.work_sids | Where-Object { $_ -notin $journal.verified_sids }).Count) { return }
    Assert-Recovery
    # Cosmetic only, not security: the account remains usable via runas / Other user.
    $userList = 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList'
    if (!(Test-Path -LiteralPath $userList)) { New-Item -Path $userList -Force | Out-Null }
    New-ItemProperty -Path $userList -Name 'azcadmin' -Value 0 -PropertyType DWord -Force | Out-Null
    Transition 'completo'
}
try {
    if (Test-Path -LiteralPath $journalPath) { $journal = Read-MigrationJournal $journalPath }
    else {
        $journal = New-MigrationJournal
        $journal.tenant_id = $TenantId.ToString(); $journal.device_id = $DeviceId.ToString()
        $journal.work_sids = @($WorkUserSids | Sort-Object -Unique)
        Save-Journal $journal
        $remote = Request-Control 'GET' "/v1/devices/$DeviceId/migration" $null
        if ($remote.device_id -ne $DeviceId.ToString()) { throw 'remote_identity_mismatch' }
        if ($remote.revision -ne 0 -or $remote.phase) { $journal.revision = $remote.revision; throw 'remote_migration_requires_assistance' }
    }
    $edition = (Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion').EditionID
    $build = [Environment]::OSVersion.Version.Build
    if ($edition -notin @('Professional', 'ProfessionalN', 'ProfessionalWorkstation') -or $build -lt 22000) { throw 'unsupported_windows' }
    if ($TenantId -eq [guid]::Empty -or $DeviceId -eq [guid]::Empty -or !$journal.work_sids.Count) { throw 'migration_identity_required' }
    if ($journal.tenant_id -ne $TenantId.ToString() -or $journal.device_id -ne $DeviceId.ToString()) { throw 'journal_identity_mismatch' }
    if (@(Compare-Object @($journal.work_sids) @($WorkUserSids | Sort-Object -Unique)).Count) { throw 'work_sids_changed' }
    Flush-Reports
    if ($Phase -eq 'Report') { return }
    if ($journal.phase -eq 'excepcion') { throw 'assisted_recovery_required' }
    if ($Phase -eq 'Install') { Install-Keeper; return }
    if ($Phase -eq 'ProvisionRecovery') { Provision-Recovery; return }
    if ($Phase -eq 'Demote') { Demote-Users; return }
    if ($Phase -eq 'Logoff') { Close-WorkSessions; return }
    if ($Phase -eq 'Verify') { Verify-Users; return }
    Install-Keeper
    Provision-Recovery
    if ($journal.safe_phase -eq 'escrow_ok') { Demote-Users }
    if ($journal.safe_phase -in @('degradado', 'pendiente_reinicio')) { Close-WorkSessions; Verify-Users }
} catch {
    # Persist exception before attempting the network; offline failures remain reportable on resume.
    if ($journal) {
        $knownFailures = @('credential_logon_failed', 'unexpected_administrator', 'builtin_administrator_enabled', 'blank_password',
            'admin_password_audit_indeterminate', 'recovered_credential_failed', 'unowned_recovery_account',
            'incomplete_recovery_requires_assistance', 'baseline_or_session_not_ready', 'enrollment_not_verified',
            'control_plane_unavailable', 'assisted_recovery_required', 'service_unavailable', 'administrator_token_survived',
            'msi_signature_invalid', 'unsupported_windows', 'work_user_readmitted_to_administrators', 'migration_ack_mismatch')
        $journal.failure = if ($_.Exception.Message -in $knownFailures) { $_.Exception.Message } else { 'bootstrap_verification_failed' }
        Add-MigrationTransition $journal 'excepcion' $journal.failure
        Save-Journal $journal
        try { Flush-Reports } catch { Write-Warning 'exception_report_pending' }
    }
    # Do not echo external exception text: adapters may include sensitive HTTP content.
    throw 'migration_exception_requires_assistance'
} finally { $lease.Dispose() }

# SIG # Begin signature block
# MIIdZAYJKoZIhvcNAQcCoIIdVTCCHVECAQExDzANBglghkgBZQMEAgEFADB5Bgor
# BgEEAYI3AgEEoGswaTA0BgorBgEEAYI3AgEeMCYCAwEAAAQQH8w7YFlLCE63JNLG
# KX7zUQIBAAIBAAIBAAIBAAIBADAxMA0GCWCGSAFlAwQCAQUABCCkVIiI1be7u6Xg
# yXQTxkJF2Tb9FDZAxZ5qp2V97R4MPaCCFzYwggP4MIICYKADAgECAhA/aTK/R5qa
# j0KV5a5IVH20MA0GCSqGSIb3DQEBCwUAMBQxEjAQBgNVBAMMCUdydXBvIEFaQzAe
# Fw0yNjA5MTgxNDQyMzBaFw0yOTA5MTgxNDUyMjlaMBQxEjAQBgNVBAMMCUdydXBv
# IEFaQzCCAaIwDQYJKoZIhvcNAQEBBQADggGPADCCAYoCggGBAMd+R0kslEjWb5mn
# 5Ttvb8r9pcmmGRZYpS9zKoGC0DArVZ3vpt1mpgwDNUs79tYyXNh9JE9F9aW2IsG/
# AYPU3hifTeCy3JsWdnmjL7KRyxBNuk3BIUtJJwBNc66TxiYIxTa5OAhgOQBk3YbI
# oG1ZY6aYt1Mdi9idtis+OZqEMxZj112FWHfqg0nKxMLJXE6B9Y/YFD5bjUOIuPtg
# npUba1GkSsxF4qomY1p71yCmTBTSwmeY906dHdDaoFmf5ZV8nOzuPxuAML6uH2wC
# L15gfMJE/lNZ6LYC3cr1ROOEoglljJWnZcLPkENCaAVqE/2hHPB/0pmfoUjzH0uY
# Hf1GxkSWkg1cBc8OT5BGaG63bZSYbwImmF7fAKbt2rmDcEGDQk2eAwfbIVgNm4g/
# KJLWwsZMU30AZZew+j1Iz4Hhl7g4LjngCwcFffNTgVWohFHLRynOvI4Ud9oif+P7
# G8ZwrSXOrGnjIlUS8+zUCnMCMC2gafHGPZdhr/8pKTsHqQTfTQIDAQABo0YwRDAO
# BgNVHQ8BAf8EBAMCB4AwEwYDVR0lBAwwCgYIKwYBBQUHAwMwHQYDVR0OBBYEFDVC
# f/gnUGG09VZ2S/xi5aVjh2FsMA0GCSqGSIb3DQEBCwUAA4IBgQA9GtlxO7X31xQS
# EBlNA4QIXXmPkXDTO7Q7rSdubOHsyHMNJAsHaCe9KY+oul1JBEAOQm+jAD2u1uvI
# esU3yxpCTU0BNeVsVGGZn8bb2vFzwbmzA2YQvULbBA05kb0t31iYRpRw0o7xp0gg
# MtlKuaNpALeyW/Id5oeGQp/JUbYbdksj7xHx3yS8ZHI7kw9jamhrLPmTXrDRjL5Z
# O2UEsU40HPZeCrSw6U4LC+m7MCRnyI3PqIYEZ0mTrLALW38+p5rvLW/GATUrh19l
# JOgUakwY3GE7AyGHhTYr/h9E7HpNlaXUfD34H1jS+hllH+9rmQJIUCIS44disfar
# LeU/gH3dUfSW8AMtqpUwls99JBk82fsl25iJJmAmeytS+cP60u44MOgmA/1z2P75
# SbOv40cYNjJkfJGpzyKO/xDRzdyKiLPERBvpzJz1pNNfVlFRzR9r8D2wntARyh85
# NHetp6tslFPJWJU0R0peVxk/52s88TR4+bJmL/iN5suQswYFJ5wwggWNMIIEdaAD
# AgECAhAOmxiO+dAt5+/bUOIIQBhaMA0GCSqGSIb3DQEBDAUAMGUxCzAJBgNVBAYT
# AlVTMRUwEwYDVQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3dy5kaWdpY2Vy
# dC5jb20xJDAiBgNVBAMTG0RpZ2lDZXJ0IEFzc3VyZWQgSUQgUm9vdCBDQTAeFw0y
# MjA4MDEwMDAwMDBaFw0zMTExMDkyMzU5NTlaMGIxCzAJBgNVBAYTAlVTMRUwEwYD
# VQQKEwxEaWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3dy5kaWdpY2VydC5jb20xITAf
# BgNVBAMTGERpZ2lDZXJ0IFRydXN0ZWQgUm9vdCBHNDCCAiIwDQYJKoZIhvcNAQEB
# BQADggIPADCCAgoCggIBAL/mkHNo3rvkXUo8MCIwaTPswqclLskhPfKK2FnC4Smn
# PVirdprNrnsbhA3EMB/zG6Q4FutWxpdtHauyefLKEdLkX9YFPFIPUh/GnhWlfr6f
# qVcWWVVyr2iTcMKyunWZanMylNEQRBAu34LzB4TmdDttceItDBvuINXJIB1jKS3O
# 7F5OyJP4IWGbNOsFxl7sWxq868nPzaw0QF+xembud8hIqGZXV59UWI4MK7dPpzDZ
# Vu7Ke13jrclPXuU15zHL2pNe3I6PgNq2kZhAkHnDeMe2scS1ahg4AxCN2NQ3pC4F
# fYj1gj4QkXCrVYJBMtfbBHMqbpEBfCFM1LyuGwN1XXhm2ToxRJozQL8I11pJpMLm
# qaBn3aQnvKFPObURWBf3JFxGj2T3wWmIdph2PVldQnaHiZdpekjw4KISG2aadMre
# Sx7nDmOu5tTvkpI6nj3cAORFJYm2mkQZK37AlLTSYW3rM9nF30sEAMx9HJXDj/ch
# srIRt7t/8tWMcCxBYKqxYxhElRp2Yn72gLD76GSmM9GJB+G9t+ZDpBi4pncB4Q+U
# DCEdslQpJYls5Q5SUUd0viastkF13nqsX40/ybzTQRESW+UQUOsxxcpyFiIJ33xM
# dT9j7CFfxCBRa2+xq4aLT8LWRV+dIPyhHsXAj6KxfgommfXkaS+YHS312amyHeUb
# AgMBAAGjggE6MIIBNjAPBgNVHRMBAf8EBTADAQH/MB0GA1UdDgQWBBTs1+OC0nFd
# ZEzfLmc/57qYrhwPTzAfBgNVHSMEGDAWgBRF66Kv9JLLgjEtUYunpyGd823IDzAO
# BgNVHQ8BAf8EBAMCAYYweQYIKwYBBQUHAQEEbTBrMCQGCCsGAQUFBzABhhhodHRw
# Oi8vb2NzcC5kaWdpY2VydC5jb20wQwYIKwYBBQUHMAKGN2h0dHA6Ly9jYWNlcnRz
# LmRpZ2ljZXJ0LmNvbS9EaWdpQ2VydEFzc3VyZWRJRFJvb3RDQS5jcnQwRQYDVR0f
# BD4wPDA6oDigNoY0aHR0cDovL2NybDMuZGlnaWNlcnQuY29tL0RpZ2lDZXJ0QXNz
# dXJlZElEUm9vdENBLmNybDARBgNVHSAECjAIMAYGBFUdIAAwDQYJKoZIhvcNAQEM
# BQADggEBAHCgv0NcVec4X6CjdBs9thbX979XB72arKGHLOyFXqkauyL4hxppVCLt
# pIh3bb0aFPQTSnovLbc47/T/gLn4offyct4kvFIDyE7QKt76LVbP+fT3rDB6mouy
# XtTP0UNEm0Mh65ZyoUi0mcudT6cGAxN3J0TU53/oWajwvy8LpunyNDzs9wPHh6jS
# TEAZNUZqaVSwuKFWjuyk1T3osdz9HNj0d1pcVIxv76FQPfx2CWiEn2/K2yCNNWAc
# AgPLILCsWKAOQGPFmCLBsln1VWvPJ6tsds5vIy30fnFqI2si/xK4VC0nftg62fC2
# h5b9W9FcrBjDTZ9ztwGpn1eqXijiuZQwgga0MIIEnKADAgECAhANx6xXBf8hmS5A
# QyIMOkmGMA0GCSqGSIb3DQEBCwUAMGIxCzAJBgNVBAYTAlVTMRUwEwYDVQQKEwxE
# aWdpQ2VydCBJbmMxGTAXBgNVBAsTEHd3dy5kaWdpY2VydC5jb20xITAfBgNVBAMT
# GERpZ2lDZXJ0IFRydXN0ZWQgUm9vdCBHNDAeFw0yNTA1MDcwMDAwMDBaFw0zODAx
# MTQyMzU5NTlaMGkxCzAJBgNVBAYTAlVTMRcwFQYDVQQKEw5EaWdpQ2VydCwgSW5j
# LjFBMD8GA1UEAxM4RGlnaUNlcnQgVHJ1c3RlZCBHNCBUaW1lU3RhbXBpbmcgUlNB
# NDA5NiBTSEEyNTYgMjAyNSBDQTEwggIiMA0GCSqGSIb3DQEBAQUAA4ICDwAwggIK
# AoICAQC0eDHTCphBcr48RsAcrHXbo0ZodLRRF51NrY0NlLWZloMsVO1DahGPNRcy
# bEKq+RuwOnPhof6pvF4uGjwjqNjfEvUi6wuim5bap+0lgloM2zX4kftn5B1IpYzT
# qpyFQ/4Bt0mAxAHeHYNnQxqXmRinvuNgxVBdJkf77S2uPoCj7GH8BLuxBG5AvftB
# dsOECS1UkxBvMgEdgkFiDNYiOTx4OtiFcMSkqTtF2hfQz3zQSku2Ws3IfDReb6e3
# mmdglTcaarps0wjUjsZvkgFkriK9tUKJm/s80FiocSk1VYLZlDwFt+cVFBURJg6z
# MUjZa/zbCclF83bRVFLeGkuAhHiGPMvSGmhgaTzVyhYn4p0+8y9oHRaQT/aofEnS
# 5xLrfxnGpTXiUOeSLsJygoLPp66bkDX1ZlAeSpQl92QOMeRxykvq6gbylsXQskBB
# BnGy3tW/AMOMCZIVNSaz7BX8VtYGqLt9MmeOreGPRdtBx3yGOP+rx3rKWDEJlIqL
# XvJWnY0v5ydPpOjL6s36czwzsucuoKs7Yk/ehb//Wx+5kMqIMRvUBDx6z1ev+7ps
# NOdgJMoiwOrUG2ZdSoQbU2rMkpLiQ6bGRinZbI4OLu9BMIFm1UUl9VnePs6BaaeE
# WvjJSjNm2qA+sdFUeEY0qVjPKOWug/G6X5uAiynM7Bu2ayBjUwIDAQABo4IBXTCC
# AVkwEgYDVR0TAQH/BAgwBgEB/wIBADAdBgNVHQ4EFgQU729TSunkBnx6yuKQVvYv
# 1Ensy04wHwYDVR0jBBgwFoAU7NfjgtJxXWRM3y5nP+e6mK4cD08wDgYDVR0PAQH/
# BAQDAgGGMBMGA1UdJQQMMAoGCCsGAQUFBwMIMHcGCCsGAQUFBwEBBGswaTAkBggr
# BgEFBQcwAYYYaHR0cDovL29jc3AuZGlnaWNlcnQuY29tMEEGCCsGAQUFBzAChjVo
# dHRwOi8vY2FjZXJ0cy5kaWdpY2VydC5jb20vRGlnaUNlcnRUcnVzdGVkUm9vdEc0
# LmNydDBDBgNVHR8EPDA6MDigNqA0hjJodHRwOi8vY3JsMy5kaWdpY2VydC5jb20v
# RGlnaUNlcnRUcnVzdGVkUm9vdEc0LmNybDAgBgNVHSAEGTAXMAgGBmeBDAEEAjAL
# BglghkgBhv1sBwEwDQYJKoZIhvcNAQELBQADggIBABfO+xaAHP4HPRF2cTC9vgvI
# tTSmf83Qh8WIGjB/T8ObXAZz8OjuhUxjaaFdleMM0lBryPTQM2qEJPe36zwbSI/m
# S83afsl3YTj+IQhQE7jU/kXjjytJgnn0hvrV6hqWGd3rLAUt6vJy9lMDPjTLxLgX
# f9r5nWMQwr8Myb9rEVKChHyfpzee5kH0F8HABBgr0UdqirZ7bowe9Vj2AIMD8liy
# rukZ2iA/wdG2th9y1IsA0QF8dTXqvcnTmpfeQh35k5zOCPmSNq1UH410ANVko43+
# Cdmu4y81hjajV/gxdEkMx1NKU4uHQcKfZxAvBAKqMVuqte69M9J6A47OvgRaPs+2
# ykgcGV00TYr2Lr3ty9qIijanrUR3anzEwlvzZiiyfTPjLbnFRsjsYg39OlV8cipD
# oq7+qNNjqFzeGxcytL5TTLL4ZaoBdqbhOhZ3ZRDUphPvSRmMThi0vw9vODRzW6Ax
# nJll38F0cuJG7uEBYTptMSbhdhGQDpOXgpIUsWTjd6xpR6oaQf/DJbg3s6KCLPAl
# Z66RzIg9sC+NJpud/v4+7RWsWCiKi9EOLLHfMR2ZyJ/+xhCx9yHbxtl5TPau1j/1
# MIDpMPx0LckTetiSuEtQvLsNz3Qbp7wGWqbIiOWCnb5WqxL3/BAPvIXKUjPSxyZs
# q8WhbaM2tszWkPZPubdcMIIG7TCCBNWgAwIBAgIQCE/cM09+RU7bww+P+ZIYNTAN
# BgkqhkiG9w0BAQsFADBpMQswCQYDVQQGEwJVUzEXMBUGA1UEChMORGlnaUNlcnQs
# IEluYy4xQTA/BgNVBAMTOERpZ2lDZXJ0IFRydXN0ZWQgRzQgVGltZVN0YW1waW5n
# IFJTQTQwOTYgU0hBMjU2IDIwMjUgQ0ExMB4XDTI2MDgwNTAwMDAwMFoXDTM3MTEw
# NDIzNTk1OVowYzELMAkGA1UEBhMCVVMxFzAVBgNVBAoTDkRpZ2lDZXJ0LCBJbmMu
# MTswOQYDVQQDEzJEaWdpQ2VydCBTSEEyNTYgUlNBNDA5NiBUaW1lc3RhbXAgUmVz
# cG9uZGVyIDIwMjYgMTCCAiIwDQYJKoZIhvcNAQEBBQADggIPADCCAgoCggIBALZ7
# pvLJ/s1K+NSbTGWz/TjGMPh8CQ6RucZCLv5anHzWJjF/NWJrFIhy24fcpKXlgRik
# y4WAawDfU3YP0BMxt9l3Dm5oCG5Z69AqEN1kgHg2epx+l+lZBcmJCcN0ASURML5u
# FIS80sZsDwO3BSkUxDjLJhBI+qiZP3aixAC/qEGLjsBNlLol9VZ7pfGEXiMlneJI
# C5/YKuizVzNFKZZEeoy/0B8Zm+nzKBgSWG52lCO1w+nCg6XpCtklTJXeIg283hw7
# TmmsZXR+SMbjbrEOvZ3fP2VxIgeR28Y90ZStd3F9VuA5RVynb/whITPAo9b75Zr4
# Ta6Mj3URm26QZYMn/FnbuTegcoRcFEZ9FOqM5T6MTdtr/n74lIT/ug0eeOzmZ6QT
# Fg33otX+bFRsIolvykE1jive4PuESaT8zzVeFWDAMDtozNgLctkGD1ZjkEyZtJrL
# l5ya0m5doH/ScpaZCZVl6pNUOCybMc/kxC6EAmSJY24L0yYKD1Nkddsnb/ItVKi/
# 2nXpQNMu1PT5prW83vV8d67WowuUs0HdY4H8AMLGvdL/WHEj3ZnqMqAQQP9u3Ai9
# t+5eQ02GDwy0ODjdzi0xlp70W+ow63/0++YDEX1M0iwgUHwbrJvfpklkZQvw3+kv
# 3vUPItdwroczk9icflf55W1zOEKAcJVAIXpcMCU9AgMBAAGjggGVMIIBkTAMBgNV
# HRMBAf8EAjAAMB0GA1UdDgQWBBQUyWOKMC7USvtulPPm40B+9ezN4jAfBgNVHSME
# GDAWgBTvb1NK6eQGfHrK4pBW9i/USezLTjAOBgNVHQ8BAf8EBAMCB4AwFgYDVR0l
# AQH/BAwwCgYIKwYBBQUHAwgwgZUGCCsGAQUFBwEBBIGIMIGFMCQGCCsGAQUFBzAB
# hhhodHRwOi8vb2NzcC5kaWdpY2VydC5jb20wXQYIKwYBBQUHMAKGUWh0dHA6Ly9j
# YWNlcnRzLmRpZ2ljZXJ0LmNvbS9EaWdpQ2VydFRydXN0ZWRHNFRpbWVTdGFtcGlu
# Z1JTQTQwOTZTSEEyNTYyMDI1Q0ExLmNydDBfBgNVHR8EWDBWMFSgUqBQhk5odHRw
# Oi8vY3JsMy5kaWdpY2VydC5jb20vRGlnaUNlcnRUcnVzdGVkRzRUaW1lU3RhbXBp
# bmdSU0E0MDk2U0hBMjU2MjAyNUNBMS5jcmwwIAYDVR0gBBkwFzAIBgZngQwBBAIw
# CwYJYIZIAYb9bAcBMA0GCSqGSIb3DQEBCwUAA4ICAQCNxTphHp1SCt+ZrAmAfn0o
# QLFr0mLywSLaDXQIENoyKqxrFbJblzCVP/pkXmwXOdrOpWygLzlT12os5ipDCy35
# RBCg2UMeApEtrfGhz45F4Wt4WGdNdIbRWt3YTYJmpR+b7lr4d7Uwn+H600u4D7Rn
# OGf8Wj4UNgAdZkfHhHv1mx9EVh71SJelcEN/oORSjXzdjfw1iZH9d8Nh/thn6hH2
# 3d+VsPAr6GAYyzSA02nXD1nYLI7Ijmiv+xLCiYC41DSFYL3GhTiy0PxpawPtGRya
# BVGzq+UiTfM8pD7KVyF5aQyWP4KhVGUUTnmm/RlYJoW3TiXA/+t0YcT2oRVBm3JE
# TjajHug2AL+v5jhtKVnd3D0rbHXEu27o+Q8p4sEWPMqKDB+qbceb6T/6WcwTwXmQ
# 9lOCLLYcsQeSWmvKqzpAec9etE14jOQAzLKWdE3w/TCaKtLRaRT7LCkRYVnhA2D7
# 3FLje1O5b3HR5eHs0NzU/+xX7NbEdcofy0W3Wdwd1XOqtlpg/JgwtKfZM5dqO94l
# bUveOiJBI+xZEbGRsMNbXmMREUTgu+Oca7Y73MPWcslIx2VhkSKSXjDbD6rgg39H
# 5Mh7QfieAIjWagkJNt68Yfim6cjEzVSiLSeZfdkr5dtFPTW6jATlWJdYeeDRGCya
# tf8R1hSjzSvdN8yWQPT9gzGCBYQwggWAAgEBMCgwFDESMBAGA1UEAwwJR3J1cG8g
# QVpDAhA/aTK/R5qaj0KV5a5IVH20MA0GCWCGSAFlAwQCAQUAoIGEMBgGCisGAQQB
# gjcCAQwxCjAIoAKAAKECgAAwGQYJKoZIhvcNAQkDMQwGCisGAQQBgjcCAQQwHAYK
# KwYBBAGCNwIBCzEOMAwGCisGAQQBgjcCARUwLwYJKoZIhvcNAQkEMSIEIDo9eBw/
# BeIkKttguDAfSvUzHe5O6V8L4YpJeqGzCgrcMA0GCSqGSIb3DQEBAQUABIIBgKRv
# /9FKJjvaGwsGnsP8A3Tm3QP/gnGbO7rPOE6MLAeYVb0pJQ3luSYr6FBGffbMyNP8
# 4UX3q/Eic23lkHTh0C+9LhL1ncplqkrWsc4L0oMgFV2Un7+mAV7bp4LLMIT+O+ub
# vosKXBceUW5MS1qsXPnc/8CR2nX8phu40sXzL9YLIRgI9Uf76B+//R4luAo/u8Jx
# bj87cM8Mldk0Awr6hv9WW73gZgaCgk1fwZtHQHqz0ocAS4ZTynrTDiVht60x0T6V
# HGK0SbOWJTti62ktBHDGL7p5pCIOXDTYOsYqHtBfhZfWgyA4veOd1hhoFi2b1r3F
# RBMMRIbGpx0rTZO0yHfDQskmcsfc6+x43FEZ1e3aD+H1qUvyWQed9zq0tjPsKiCm
# ZOUDaaKT19OVexL5UYK+psibgBLwt2BHoHkmuSgYALQwIN1hPesDEz0WIQlNemIK
# f7Tl379iMiUrRl91ro+bwRGmKj2pEUvyyqA3wjTj/5rdPT+zMPR6jVseYwS9yaGC
# AyYwggMiBgkqhkiG9w0BCQYxggMTMIIDDwIBATB9MGkxCzAJBgNVBAYTAlVTMRcw
# FQYDVQQKEw5EaWdpQ2VydCwgSW5jLjFBMD8GA1UEAxM4RGlnaUNlcnQgVHJ1c3Rl
# ZCBHNCBUaW1lU3RhbXBpbmcgUlNBNDA5NiBTSEEyNTYgMjAyNSBDQTECEAhP3DNP
# fkVO28MPj/mSGDUwDQYJYIZIAWUDBAIBBQCgaTAYBgkqhkiG9w0BCQMxCwYJKoZI
# hvcNAQcBMBwGCSqGSIb3DQEJBTEPFw0yNjA5MTgxNDU0MjZaMC8GCSqGSIb3DQEJ
# BDEiBCD9YletjcLEu2Pi3961xTvaHLA/Dz0gPgKUd4D55k2OfTANBgkqhkiG9w0B
# AQEFAASCAgBZxBoodTIC8JuMU9HahH9iyjviv8dpIVdrv1atPwhgK068q5cypDsP
# o6SCgf5KrJ4M+AoLz2t2XjQxGwQ9r7yHFe0SEr0eh/xoJaSC83OkwUnCKoJGoTBr
# MXvB5Ahinfh4MRdlw9l+QpEWKdwvgsD1EJUo+HJ/nNIR1DbNtz67K/9tVMcaKMvH
# FRty4AvPGJF8DBbRPWVc/gk2OEQ+Gr1YLizF+bpFQics7BqyomZKuP+iTQBjhvzg
# QBMrD5pE2zPiyqTNM6y4td8ualowiS8K2gtAiEtFtrarB4j82rdfJstjEZOLJ24N
# UIodlGasZAaTi/7DrA9CC/xqn7xs7WMdKaaoQVFAF3Due1pvCLU9Yzb4DxUN6kv7
# hNRAlmjaCu5nrklO/RI8ouDJP4Uk8z7X2ZImYXxv36zQfL3+fSsGrLqZFBLP0B5y
# PFmJKKmMbzUyurzlzkdNw5F/N0SMOeN24fxAo5nLjse7iAKrct41iWrmT87PGZIw
# 2+W/jZsXM7ovmEGnt4qKoqK3Fjg+mj13e7ffF4VjHpIVgUkLE7GLoBCDwWOCdAhB
# ZbF6MDfeRB08O4KtKlTicZ8eS9QiLX6muwPR+hKKTiUyYnNyVNAwo8DMonFTTQ+A
# pAv69VQ8Pv4tffiJhFWJpQblXVdBsF3qKQnDBICttZnDopXG3bUrGw==
# SIG # End signature block
