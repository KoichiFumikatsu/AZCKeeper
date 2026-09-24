Set-StrictMode -Version Latest
$script:MigrationPhases = @('instalado', 'enrolado', 'escrow_ok', 'degradado', 'pendiente_reinicio', 'completo')

function New-MigrationJournal {
    [pscustomobject]@{
        schema = 2; tenant_id = $null; device_id = $null; installed = $false; phase = $null; safe_phase = $null; revision = 0
        recovery_sid = $null; credential_tested = $false; escrow = $null; escrow_verified = $false
        work_sids = @(); prior_membership = @(); demoted = @(); logoff_started = $false
        password_audits = @()
        logged_off = @(); verified_sids = @(); pending_reports = @(); failure = $null
    }
}

function Read-MigrationJournal([string]$Path) {
    $state = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
    if (!$state.PSObject.Properties['schema'] -or $state.schema -ne 2) { throw 'legacy_journal_requires_assistance' }
    foreach ($property in (New-MigrationJournal).PSObject.Properties.Name) {
        if (!$state.PSObject.Properties[$property]) { throw 'invalid_journal' }
    }
    if ($state.phase -and $state.phase -notin ($script:MigrationPhases + 'excepcion')) { throw 'invalid_journal_phase' }
    if ($state.safe_phase -and $state.safe_phase -notin $script:MigrationPhases) { throw 'invalid_journal_safe_phase' }
    if ($state.revision -lt 0 -or ($state.phase -and $state.phase -ne 'excepcion' -and $state.phase -ne $state.safe_phase)) { throw 'invalid_journal_revision' }
    if ($state.safe_phase -and !$state.installed) { throw 'invalid_journal_installation' }
    if ([array]::IndexOf($script:MigrationPhases, $state.safe_phase) -ge 2 -and (!$state.recovery_sid -or !$state.credential_tested -or !$state.escrow_verified -or !$state.escrow)) { throw 'invalid_journal_recovery' }
    if ([array]::IndexOf($script:MigrationPhases, $state.safe_phase) -ge 3 -and @($state.work_sids | Where-Object { $_ -notin $state.demoted }).Count) { throw 'invalid_journal_demotions' }
    $previous = 0
    foreach ($report in $state.pending_reports) {
        if ($report.revision -le $previous -or $report.revision -gt $state.revision -or $report.phase -notin ($script:MigrationPhases + 'excepcion')) { throw 'invalid_journal_reports' }
        $previous = $report.revision
    }
    return $state
}

function Add-MigrationTransition($State, [string]$Next, [string]$Code = '') {
    if ($Code -and $Code -notmatch '^[a-zA-Z0-9_.-]{1,80}$') { throw 'invalid_report_code' }
    if ($Next -eq $State.phase) { return }
    $before = [array]::IndexOf($script:MigrationPhases, $State.safe_phase)
    $after = [array]::IndexOf($script:MigrationPhases, $Next)
    if ($Next -ne 'excepcion' -and ($after -lt 0 -or $after -ne ($before + 1))) { throw 'invalid_phase_transition' }
    if ($State.phase -eq 'excepcion' -and $Next -ne 'excepcion') { throw 'assisted_recovery_required' }
    if ($after -ge 2 -and (!$State.credential_tested -or !$State.escrow_verified -or !$State.recovery_sid)) { throw 'recovery_not_verified' }
    if ($Next -eq 'completo' -and (@($State.work_sids).Count -eq 0 -or @($State.work_sids | Where-Object { $_ -notin $State.verified_sids }).Count -ne 0)) { throw 'standard_tokens_not_verified' }
    $State.revision++
    $report = [ordered]@{ phase = $Next; revision = $State.revision }
    if ($Next -eq 'completo') { $report.standard_user_verified = $true }
    if ($Code) { $report.code = $Code }
    $State.pending_reports = @($State.pending_reports) + [pscustomobject]$report
    $State.phase = $Next
    if ($Next -ne 'excepcion') { $State.safe_phase = $Next }
}

function Get-AdministratorAuditFailure($Accounts, $Members, [string]$RecoverySid, [string[]]$WorkSids) {
    foreach ($account in $Accounts) {
        if ($account.Enabled -and $account.Sid -match '-500$') { return 'builtin_administrator_enabled' }
        if ($account.Enabled -and $account.BlankPassword -eq 'confirmed') { return 'blank_password' }
    }
    foreach ($sid in $Members) {
        if ($sid -match '-500$' -and @($Accounts | Where-Object { $_.Sid -eq $sid -and !$_.Enabled }).Count -eq 1) { continue }
        if ($sid -ne $RecoverySid -and $sid -notin $WorkSids) { return 'unexpected_administrator' }
    }
    return $null
}

# SIG # Begin signature block
# MIIdZAYJKoZIhvcNAQcCoIIdVTCCHVECAQExDzANBglghkgBZQMEAgEFADB5Bgor
# BgEEAYI3AgEEoGswaTA0BgorBgEEAYI3AgEeMCYCAwEAAAQQH8w7YFlLCE63JNLG
# KX7zUQIBAAIBAAIBAAIBAAIBADAxMA0GCWCGSAFlAwQCAQUABCDrTpM/Fx9wtBJD
# 4afjJJzQFemjBtizVBYSVTQY02zjqqCCFzYwggP4MIICYKADAgECAhA/aTK/R5qa
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
# KwYBBAGCNwIBCzEOMAwGCisGAQQBgjcCARUwLwYJKoZIhvcNAQkEMSIEIPQXAjQO
# 3uKxWAPTamNFpqVRxNLy6V/Lhztn6rG6bRF/MA0GCSqGSIb3DQEBAQUABIIBgKzL
# XiXWUpfVf0j+Qgzi+UiQC9DC3tL8dYPIj7vYVXCU3VhlwZq5cegfqcFdYWeqikkS
# 01jdrLn/e8GJtRTdWsvIENgpOxzPeP8KSjRFojQsDY9ZlOV27uYxvIK5LfnDuex2
# jQVi1wcxoI5UN/5fjz305dZls9MxnX8nFaIeCgoSW7BnN+EK9i/8+0XOgu6+1JbS
# RyP+XU7EZIvULNH0vjlKm5kq1ZWQolSGIaE7ARyVoCGNmI+5bRHJPlpGw3kAgt5n
# xsmT2bQnv3IjPICIsGVnPCRII+KQvP6EupbxBThRNQtE1svUVmDqMKlCDiH66Clg
# j5mA580diEd2rTFL9GTMUtmgTgi3C9j4eFeq72EmVYZfP+bK5X5sSogfAiIMmw9e
# dOQzJ7qOHYRRaa2++aBU30604+aNbTjKAvXWKIfYOYUMJQMh//HEqfqlQN2G8WvO
# 4+mDPgAyHIKruq1YAqWhIIFipvO99gHVvGKKQCWqxHOcRKRwtRIuplaQJU5bsKGC
# AyYwggMiBgkqhkiG9w0BCQYxggMTMIIDDwIBATB9MGkxCzAJBgNVBAYTAlVTMRcw
# FQYDVQQKEw5EaWdpQ2VydCwgSW5jLjFBMD8GA1UEAxM4RGlnaUNlcnQgVHJ1c3Rl
# ZCBHNCBUaW1lU3RhbXBpbmcgUlNBNDA5NiBTSEEyNTYgMjAyNSBDQTECEAhP3DNP
# fkVO28MPj/mSGDUwDQYJYIZIAWUDBAIBBQCgaTAYBgkqhkiG9w0BCQMxCwYJKoZI
# hvcNAQcBMBwGCSqGSIb3DQEJBTEPFw0yNjA5MTgxNDU0MjdaMC8GCSqGSIb3DQEJ
# BDEiBCB6t/2nq2SGDH7fIZeEbDChyNX1W3tLqRJgDbHSn4M7nzANBgkqhkiG9w0B
# AQEFAASCAgAUx12ZtBoYQnugx+mk3nzA0sp0RnQTecJg8vJLMXLfcN9BqZDMoxbm
# FnNdbetYnRIaAy/kqxXC1akWjja1XNXsaRKT5pJeOLgwm/y3kQofvyFi9B+2HfVf
# ilF2UfYWE9KEePtEyk2XJCUROpXLydX7mymjp6hSlVnWTd/HMzMSK4r2yRTt4Req
# Bd5TNNOLccTvaAe4zghmvNNDrnzetJrY5owxKT+zCSpzQg/RG74xBGDOlKxAkwHD
# atxSsXtRY0pdDUkkuenIcA2Ao/8oni8UM/Mx/QXZqzHp5awv0rUnwt9O86U85l55
# 23pQ8Peh8rf7T/Rt7VOStoGkq8PU/w5ZcSw1fndQJFfIZ2cumRKg5lUdOuCZiF33
# TelHR/S6r4CC3QIUsHFq+GfnxclHYe2LWZbKAq5lOGWyCv5Vy3xbLgtYQAmWRjF+
# 34SpeUATHN3E6FZ8PbxoZfznCs7WzfKc1z61upjrj+rYIlAooWGHGPf25zVfq7jn
# XD1WBWufPJzLQadnRv6uB/u5Yyyw3ui1n9QHd0cWCQKuRlirgI58AEpJM/meMBu7
# vUO8xq3kC1FwR1ubfJD+wKAdQX58SCER415maPiveNkXzd5UNO8hYxACmSueqbfq
# NLRKILhEB876tAk9X7IMmRiw2m2UO+pbHausQKxbPjDS0aP5S0z4vw==
# SIG # End signature block
