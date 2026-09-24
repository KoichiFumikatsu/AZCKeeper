function Invoke-EnrollmentHelper([string]$Mode, $InputDocument) {
    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = Join-Path $PSScriptRoot 'Keeper.Bridge.exe'
    $start.Arguments = $Mode
    $start.UseShellExecute = $false; $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true; $start.RedirectStandardOutput = $true; $start.RedirectStandardError = $true
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $start
    try {
        [void]$process.Start()
        $outputTask = $process.StandardOutput.ReadToEndAsync()
        $errorTask = $process.StandardError.ReadToEndAsync()
        # Enrollment ticket only on stdin, never in arguments or service/MSI properties.
        $process.StandardInput.WriteLine(($InputDocument | ConvertTo-Json -Compress))
        $process.StandardInput.Close()
        if (!$process.WaitForExit(180000)) { $process.Kill(); throw 'enrollment_helper_timeout' }
        $output = $outputTask.GetAwaiter().GetResult()
        [void]$errorTask.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0) { return $null }
        return ($output | ConvertFrom-Json)
    } finally { $process.Dispose() }
}

function Invoke-KeeperMigrationEnrollment([guid]$TenantId, [guid]$DeviceId) {
    $inputDocument = @{ TenantId = $TenantId.ToString(); DeviceId = $DeviceId.ToString(); ApiBase = $deployment.api_base; Ticket = $null }
    $public = Invoke-EnrollmentHelper '--enrollment-key' $inputDocument
    if (!$public -or $public.public_key_thumbprint -notmatch '^[a-f0-9]{64}$') { throw 'device_key_not_ready' }
    # Recover a lost login ACK using the device's own key, without consuming/reissuing another ticket.
    $existing = Invoke-EnrollmentHelper '--enroll' $inputDocument
    if ($existing) { return $existing }
    $authorization = Invoke-KeeperMigrationControl 'POST' '/v1/migration/authorizations' @{ device_id = $DeviceId.ToString(); public_key_thumbprint = $public.public_key_thumbprint; expires_in = 600 }
    if ($authorization.device_id -ne $DeviceId.ToString() -or $authorization.tenant_id -ne $TenantId.ToString() -or [datetimeoffset]$authorization.expires_at -le [datetimeoffset]::UtcNow) { throw 'migration_authorization_mismatch' }
    $validation = Invoke-KeeperMigrationControl 'POST' '/v1/migration/authorizations/validate' @{ device_id = $DeviceId.ToString(); token = $authorization.token }
    if (!$validation.valid -or $validation.device_id -ne $DeviceId.ToString() -or [datetimeoffset]$validation.expires_at -le [datetimeoffset]::UtcNow) { throw 'migration_authorization_invalid' }
    $inputDocument.Ticket = $authorization.token
    $result = Invoke-EnrollmentHelper '--enroll' $inputDocument
    if (!$result) { throw 'device_enrollment_failed' }
    return $result
}
