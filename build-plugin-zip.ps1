# Build plugin zip with forward-slash paths so it extracts correctly on all servers.
# PowerShell's Compress-Archive can use backslashes, which breaks on Linux and causes
# "Plugin file does not exist" when WordPress looks for the plugin file.
$ErrorActionPreference = "Stop"
$root = $PSScriptRoot
$pluginDir = "aqm-formidable-zip-whitelist"
$mainFile = "aqm-formidable-zip-whitelist.php"
$zipName = "aqm-formidable-zip-whitelist.zip"
$zipPath = Join-Path $root $zipName
$phpPath = Join-Path $root $mainFile

if (-not (Test-Path $phpPath)) {
    Write-Error "Plugin file not found: $phpPath"
    exit 1
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# Remove existing zip
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

# Create zip with explicit forward-slash entry names (ZIP spec; works on all OS)
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $entryName = "$pluginDir/$mainFile"   # forward slashes
    $entry = $zip.CreateEntry($entryName)
    $bytes = [System.IO.File]::ReadAllBytes($phpPath)
    $stream = $entry.Open()
    $stream.Write($bytes, 0, $bytes.Length)
    $stream.Close()
} finally {
    $zip.Dispose()
}

Write-Host "Created: $zipPath"
Write-Host "Contents: $pluginDir/$mainFile"
Get-Item $zipPath | Select-Object Name, Length, LastWriteTime
