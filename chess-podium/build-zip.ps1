# Build Chess Podium ZIP for WordPress.org submission
# Run from chess-podium folder: .\build-zip.ps1

$version = "0.3.0"
$outDir = "..\dist"
$zipName = "chess-podium-$version.zip"

# Create dist folder
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir | Out-Null }

# Files/folders to include
$include = @(
    "chess-podium.php",
    "readme.txt",
    "includes",
    "assets",
    "languages",
    "lib"
)

$tempDir = Join-Path $env:TEMP "chess-podium-build"
if (Test-Path $tempDir) { Remove-Item $tempDir -Recurse -Force }
New-Item -ItemType Directory -Path $tempDir | Out-Null

$destDir = Join-Path $tempDir "chess-podium"
New-Item -ItemType Directory -Path $destDir | Out-Null

# Copy from current directory (script runs from chess-podium folder)
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $scriptDir

foreach ($item in $include) {
    $src = $item
    if (Test-Path $src) {
        if (Test-Path $src -PathType Container) {
            Copy-Item -Path $src -Destination (Join-Path $destDir (Split-Path $src -Leaf)) -Recurse -Force
        } else {
            Copy-Item -Path $src -Destination $destDir -Force
        }
    }
}

$zipPath = Join-Path $outDir $zipName
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
# Zip must contain chess-podium/ folder so WordPress installs correctly
Compress-Archive -Path $destDir -DestinationPath $zipPath -Force

# Also create Pro package (same content, for distribution on chesspodium.com)
$proZipPath = Join-Path $outDir "chess-podium-pro-$version.zip"
Copy-Item $zipPath $proZipPath -Force
Write-Host "Created: $zipPath (WordPress.org)"
Write-Host "Created: $proZipPath (Pro distribution)"
