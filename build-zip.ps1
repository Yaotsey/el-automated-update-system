$ErrorActionPreference = 'Stop'

$root   = $PSScriptRoot
$source = Join-Path $root 'el-automated-update-system'
$dest   = Join-Path $root 'el-automated-update-system.zip'
$base   = Split-Path -Leaf $source

if (Test-Path -LiteralPath $dest) {
    Remove-Item -LiteralPath $dest -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zip = [System.IO.Compression.ZipFile]::Open($dest, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    Get-ChildItem -LiteralPath $source -Recurse -File | ForEach-Object {
        $relative  = $_.FullName.Substring($source.Length + 1).Replace('\', '/')
        $entryName = $base + '/' + $relative
        $entry     = $zip.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $fileStream  = [System.IO.File]::OpenRead($_.FullName)
        try {
            $fileStream.CopyTo($entryStream)
        } finally {
            $fileStream.Dispose()
            $entryStream.Dispose()
        }
    }
} finally {
    $zip.Dispose()
}

Write-Output 'ZIP_BUILT'
