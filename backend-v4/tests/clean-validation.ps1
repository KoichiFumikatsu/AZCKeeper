param([string]$Subdirectory = '', [ValidateSet('.validation','.validation-deps')][string]$RootDirectory = '.validation')
$ErrorActionPreference = 'Stop'
$backend = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$root = [IO.Path]::GetFullPath((Join-Path $backend $RootDirectory))
if ($Subdirectory -ne '') {
    $candidate = [IO.Path]::GetFullPath((Join-Path $root $Subdirectory))
    if (-not $candidate.StartsWith($root + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup requires a validation subdirectory' }
    $root = $candidate
}
if (-not (Test-Path -LiteralPath $root)) { exit 0 }
if ((Get-Item -LiteralPath $root -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Cleanup refuses a redirected validation root' }
if (-not (Get-Item -LiteralPath $root -Force).PSIsContainer) {
    Remove-Item -LiteralPath $root -Force
    Write-Output 'Validation file removed.'
    exit 0
}
$prefix = $root + [IO.Path]::DirectorySeparatorChar
$entries = @(Get-ChildItem -LiteralPath $root -Recurse -Force)
foreach ($entry in $entries) {
    if (-not $entry.FullName.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) -or ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Cleanup refuses paths outside validation or reparse points'
    }
}
foreach ($entry in $entries | Where-Object { -not $_.PSIsContainer }) { Remove-Item -LiteralPath $entry.FullName -Force }
foreach ($entry in $entries | Where-Object { $_.PSIsContainer } | Sort-Object { $_.FullName.Length } -Descending) {
    if (@(Get-ChildItem -LiteralPath $entry.FullName -Force).Count -ne 0) { throw 'Cleanup refuses nonempty directory' }
    Remove-Item -LiteralPath $entry.FullName
}
if (@(Get-ChildItem -LiteralPath $root -Force).Count -ne 0) { throw 'Validation directory not empty' }
Remove-Item -LiteralPath $root
Write-Output 'Validation files and empty directories removed.'
