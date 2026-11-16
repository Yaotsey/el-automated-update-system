<#
.SYNOPSIS
    Prepare a release of the EL Automated Update System plugin.

.DESCRIPTION
    Bumps the plugin version, updates the readme stable tag, rebuilds the
    uploadable zip with build-zip.ps1, and prints the git and GitHub release
    commands to run next.

    This script does not push, tag, or create the GitHub release. It prints
    those commands so you can review them before running anything that
    changes the remote repository.

.PARAMETER Version
    The new version number, for example 1.1.1. Must be higher than the
    current version.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File .\release.ps1 -Version 1.1.1
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string]$Version
)

$ErrorActionPreference = 'Stop'

$root       = $PSScriptRoot
$pluginDir  = Join-Path $root 'el-automated-update-system'
$mainFile   = Join-Path $pluginDir 'el-automated-update-system.php'
$readmeFile = Join-Path $pluginDir 'readme.txt'
$buildZip   = Join-Path $root 'build-zip.ps1'
$zipFile    = Join-Path $root 'el-automated-update-system.zip'
$repo       = 'Yaotsey/el-automated-update-system'

foreach ($path in @($mainFile, $readmeFile, $buildZip)) {
    if (-not (Test-Path -LiteralPath $path)) {
        throw "Required file not found: $path"
    }
}

# Read the current version from the main plugin file.
$mainContent = Get-Content -LiteralPath $mainFile -Raw
if ($mainContent -notmatch "define\(\s*'EL_AAS_VERSION'\s*,\s*'([^']+)'\s*\)") {
    throw "Could not find EL_AAS_VERSION in $mainFile"
}
$currentVersion = $Matches[1]

Write-Output "Current version: $currentVersion"
Write-Output "New version:     $Version"

if ([version]$Version -le [version]$currentVersion) {
    throw "The new version ($Version) must be greater than the current version ($currentVersion)."
}

# Update the plugin header Version line.
# Use [ \t]* rather than \s* so the CR in a CRLF file is not consumed.
$mainContent = [regex]::Replace(
    $mainContent,
    "(?m)^([ \t]*\*[ \t]*Version:[ \t]*)[0-9]+\.[0-9]+\.[0-9]+[ \t]*$",
    "`${1}$Version"
)

# Update the EL_AAS_VERSION constant.
$mainContent = [regex]::Replace(
    $mainContent,
    "(define\(\s*'EL_AAS_VERSION'\s*,\s*')[^']+('\s*\))",
    "`${1}$Version`${2}"
)

Set-Content -LiteralPath $mainFile -Value $mainContent -Encoding UTF8 -NoNewline

# Update the readme stable tag.
$readmeContent = Get-Content -LiteralPath $readmeFile -Raw
$readmeContent = [regex]::Replace(
    $readmeContent,
    "(?m)^(Stable tag:[ \t]*)[0-9]+\.[0-9]+\.[0-9]+[ \t]*$",
    "`${1}$Version"
)
Set-Content -LiteralPath $readmeFile -Value $readmeContent -Encoding UTF8 -NoNewline

Write-Output 'Version bumped in plugin header, constant, and readme.'

# Rebuild the uploadable zip.
& $buildZip

if (-not (Test-Path -LiteralPath $zipFile)) {
    throw "The build script did not produce $zipFile"
}

$zipInfo = Get-Item -LiteralPath $zipFile
Write-Output "Package built: $($zipInfo.FullName) ($($zipInfo.Length) bytes)"

Write-Output ''
Write-Output 'Next steps. Review each command before running it.'
Write-Output ''
Write-Output "  git -C `"$root`" add -A"
Write-Output "  git -C `"$root`" commit -m `"Release v$Version`""
Write-Output "  git -C `"$root`" tag v$Version"
Write-Output "  git -C `"$root`" push origin main"
Write-Output "  git -C `"$root`" push origin v$Version"
Write-Output ''
Write-Output "Then create the GitHub release and attach the zip:"
Write-Output ''
Write-Output "  gh release create v$Version `"$zipFile`" --repo $repo --title `"v$Version`" --notes `"Release v$Version`""
Write-Output ''
Write-Output 'If the gh CLI is not installed, create the release in the GitHub web UI'
Write-Output "and upload $zipFile as the release asset."
Write-Output ''
Write-Output 'After the release exists, open the plugin settings page and click'
Write-Output '"Check for Updates Now" to refresh the WordPress update check.'
