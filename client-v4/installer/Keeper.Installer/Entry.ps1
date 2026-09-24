[CmdletBinding()]
param([switch]$Schedule)
$ErrorActionPreference = 'Stop'
$taskName = 'AZCKeeper-v4-Migration'
if ($Schedule) {
    $action = New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "' + $PSCommandPath + '"')
    $triggers = @((New-ScheduledTaskTrigger -AtStartup), (New-ScheduledTaskTrigger -AtLogOn))
    $principal = New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30)
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Principal $principal -Settings $settings -Force | Out-Null
    $scheduler = New-Object -ComObject 'Schedule.Service'
    $scheduler.Connect()
    $scheduler.GetFolder('\').GetTask($taskName).SetSecurityDescriptor('O:BAG:SYD:P(A;;GA;;;SY)(A;;GA;;;BA)', 0)
    Start-ScheduledTask -TaskName $taskName
    return
}
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
try { if (!$identity.IsSystem) { throw 'system_worker_required' } } finally { $identity.Dispose() }
# This directory is extracted only after ES256/hash/editor verification and is writable only by SYSTEM/Admins.
$pin = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'publisher-pin.json') -Raw | ConvertFrom-Json
$deployment = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'deployment.json') -Raw | ConvertFrom-Json
$certificatePath = Join-Path $PSScriptRoot 'publisher.cer'
$certificate = New-Object Security.Cryptography.X509Certificates.X509Certificate2($certificatePath)
# Integration implements the IT control channel; no user/admin credential is stored in deployment.json.
. (Join-Path $PSScriptRoot 'Integration.ps1')
. (Join-Path $PSScriptRoot 'Migration.Enrollment.ps1')
$failurePath = Join-Path $PSScriptRoot 'worker-failure.json'
try {
    if (Test-Path -LiteralPath $failurePath) { throw 'assisted_recovery_required' }
    & (Join-Path $PSScriptRoot 'Bootstrap.ps1') -Phase All -MsiPath (Join-Path $PSScriptRoot 'Keeper.msi') `
        -PublisherThumbprint $certificate.Thumbprint -PublisherCertificatePath $certificatePath -PublisherCertificateSha256 $pin.CertificateSha256 `
        -TenantId $deployment.tenant_id -DeviceId $deployment.device_id -WorkUserSids $deployment.work_user_sids `
        -ControlPlane ${function:Invoke-KeeperMigrationControl} -EnrollDevice ${function:Invoke-KeeperMigrationEnrollment} -ApplyBaseline ${function:Invoke-KeeperMigrationBaseline}
    $journal = Get-Content -LiteralPath (Join-Path $env:ProgramData 'AZCKeeper\v4\bootstrap-journal.json') -Raw | ConvertFrom-Json
    if ($journal.phase -eq 'completo' -and !$journal.pending_reports.Count) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false }
} catch {
    # Covers failures before Bootstrap can load its journal (legacy/corrupt journal, native loading, preflight).
    '{"phase":"excepcion","code":"migration_worker_failed"}' | Set-Content -LiteralPath $failurePath -Encoding UTF8
    $emergencyPath = Join-Path $PSScriptRoot 'exception-report.json'
    try {
        if (Test-Path -LiteralPath $emergencyPath) { $report = Get-Content -LiteralPath $emergencyPath -Raw | ConvertFrom-Json }
        else {
            $state = Invoke-KeeperMigrationControl 'GET' "/v1/devices/$($deployment.device_id)/migration" $null
            if ($state.device_id -ne $deployment.device_id) { throw 'control_identity_mismatch' }
            if ($state.phase -eq 'excepcion') { throw 'exception_already_reported' }
            $report = [ordered]@{ phase = 'excepcion'; revision = $state.revision + 1; code = 'bootstrap_preflight_failed' }
            $report | ConvertTo-Json | Set-Content -LiteralPath $emergencyPath -Encoding UTF8
        }
        $ack = Invoke-KeeperMigrationControl 'PUT' "/v1/devices/$($deployment.device_id)/migration" $report
        if ($ack.device_id -ne $deployment.device_id -or $ack.phase -ne 'excepcion' -or $ack.revision -ne $report.revision) { throw 'exception_ack_mismatch' }
    } catch { Write-Warning 'migration_exception_report_requires_assistance' }
    throw 'migration_worker_failed'
} finally { $certificate.Dispose() }

# SIG # Begin signature block
# MIIdZAYJKoZIhvcNAQcCoIIdVTCCHVECAQExDzANBglghkgBZQMEAgEFADB5Bgor
# BgEEAYI3AgEEoGswaTA0BgorBgEEAYI3AgEeMCYCAwEAAAQQH8w7YFlLCE63JNLG
# KX7zUQIBAAIBAAIBAAIBAAIBADAxMA0GCWCGSAFlAwQCAQUABCAO+xH1qudSdx4v
# lkLB/MVtCzT8mMrZNq4OqqBcdoqBZKCCFzYwggP4MIICYKADAgECAhA/aTK/R5qa
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
# KwYBBAGCNwIBCzEOMAwGCisGAQQBgjcCARUwLwYJKoZIhvcNAQkEMSIEIJ8YjYoE
# uSUyiOd3cZPwfDFC0Aq0bTRknEsJxhZRXTOaMA0GCSqGSIb3DQEBAQUABIIBgIr4
# s8SS1bWvj9RKDJn1XWuppXbXr3CZb2d1csz+6dBJvK4AhF3W7vjMbDL1ZtGLQtEq
# evIXkeQt9vg7TuPjaKeZOBSesUy5jyvJb5iZalndC79dHLiSGRNWHhStsmvl9IfO
# J6y8V6EoQytx9Qp2Ve8lMYXgELK8mbRoFYao2/F3vnyw2wVQhDaGeXnRMakOIRoA
# KOtiSiiO4+kAdvqyfdZd07VjOCHK/F4gYrhYkZ36rOCV4w6YQbEivXzDi3L0/9gc
# Wesp5oV6doyj3oj5eNoFG4v+vQ8wC9ez2kqMt5TfjSI5Ggl/q1k7f7EeZ95T5ygd
# FyxN5eiwmLZ62eic81o5mdkHOH67p3d1Ak56p2Mrw4yc1/Q3plwQOwueMfTCH9gb
# w2GbNxiO6Z+daz51xW/NDN5/d1HIZ96VgNG8On4N9noKVC4QZnvzS5avVhxoft+E
# hRWEHURxC2KyKyjyNSzYSW0o8eh3B9zzdV142iWPyKZJXQZAib7fgAh1T8JiLKGC
# AyYwggMiBgkqhkiG9w0BCQYxggMTMIIDDwIBATB9MGkxCzAJBgNVBAYTAlVTMRcw
# FQYDVQQKEw5EaWdpQ2VydCwgSW5jLjFBMD8GA1UEAxM4RGlnaUNlcnQgVHJ1c3Rl
# ZCBHNCBUaW1lU3RhbXBpbmcgUlNBNDA5NiBTSEEyNTYgMjAyNSBDQTECEAhP3DNP
# fkVO28MPj/mSGDUwDQYJYIZIAWUDBAIBBQCgaTAYBgkqhkiG9w0BCQMxCwYJKoZI
# hvcNAQcBMBwGCSqGSIb3DQEJBTEPFw0yNjA5MTgxNDU0MjdaMC8GCSqGSIb3DQEJ
# BDEiBCAeclzniHPFI4giLiqBV8SnUlhpYHjIl/EA6OX6UDO4azANBgkqhkiG9w0B
# AQEFAASCAgCDoPc1xb7DJ+z11u1N7hqg2ufcKR9kQ2L0+vYpqWZt+kRGHGJq5XlN
# IDvKpgxuiJ2lnKfAwbUphZK+7IDfPwdg1RSTeiAbiLPwPYsqAYdTyXgpUSuX3HlS
# BqlA9DLrV0FnYvTRIplmAbjoMhpkGnkgIbUfpINIod6+qXoQGL/QltdfOUHwmegD
# nJI8MH4moSTvt+BeG0R3bhbpwbfS4qGuC+jP/6ra1Rvpg4ZFhmBs87fOE4YgZRkw
# Z7YYfLBhyKsb6ngavGGN7OgOeLYW7HXrxOVtCGuSvvZ+dwNhagidYQBjA4hXR5Ym
# rFhOY3/ZdR4+Hm9egdBampLA6vb7i6mOlA+MljG8v3MdfBaApFAIG3+7wdXu83yp
# rXm+FDNOjNIO0DqLnqLyHKvHkIpgEvLprY8cHEHtwKSyoSaNSzuLHjsZFWdz5J25
# RGwtI0JGY3utzA/erx4VzPkutMBl0SHVmNdLzrxpKGvQOeB5/x6ovjzV5LwKpRyU
# 8xY2lpXJav1f3mk5DGWTCkILXe/XNgR+j8gB716hk2Nf9iWOCxQCGwXnrVSJfMUy
# W+IFmlMpwUhFtvaxW/O5cB68+JbO4V1XPOipMNvgLq0veYkHX09dQEPLX248fqq1
# H5SDGC45FY5atUmCTG62C1rHXThbhgK3cg0I5bF9qH2h/fRfwRBULQ==
# SIG # End signature block
