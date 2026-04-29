param(
	[string]$PluginSlug = 'wp-hosting-benchmark',
	[string]$MainFile = 'wp-hosting-benchmark.php',
	[string]$OutputDir = 'dist'
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$mainFilePath = Join-Path $repoRoot $MainFile

if (-not (Test-Path -LiteralPath $mainFilePath)) {
	throw "Main plugin file not found: $mainFilePath"
}

$mainFileContent = Get-Content -LiteralPath $mainFilePath -Raw
$versionMatch = [regex]::Match($mainFileContent, '(?m)^[\s\/*#@]*Version:\s*(.+)$')

if (-not $versionMatch.Success) {
	throw "Could not determine plugin version from $MainFile"
}

$version = $versionMatch.Groups[1].Value.Trim()
$outputRoot = Join-Path $repoRoot $OutputDir
$stageRoot = Join-Path $outputRoot $PluginSlug
$zipPath = Join-Path $outputRoot ("{0}-{1}.zip" -f $PluginSlug, $version)


$includePaths = @(
	$MainFile,
	'readme.txt',
	'uninstall.php',
	'src',
	'languages'
)

if (Test-Path -LiteralPath $outputRoot) {
	Remove-Item -LiteralPath $outputRoot -Recurse -Force
}

New-Item -ItemType Directory -Path $stageRoot -Force | Out-Null

foreach ($entry in $includePaths) {
	$sourcePath = Join-Path $repoRoot $entry

	if (-not (Test-Path -LiteralPath $sourcePath)) {
		continue
	}

	$destinationPath = Join-Path $stageRoot $entry
	$sourceItem = Get-Item -LiteralPath $sourcePath

	if ($sourceItem.PSIsContainer) {
		Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Recurse -Force
	} else {
		Copy-Item -LiteralPath $sourcePath -Destination $destinationPath -Force
	}
}

if (Test-Path -LiteralPath $zipPath) {
	Remove-Item -LiteralPath $zipPath -Force
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$zipArchive = [System.IO.Compression.ZipFile]::Open($zipPath, [System.IO.Compression.ZipArchiveMode]::Create)

try {
	$files = Get-ChildItem -LiteralPath $stageRoot -Recurse -File

	foreach ($file in $files) {
		$entryName = $file.FullName.Substring($outputRoot.Length + 1).Replace('\', '/')
		[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $file.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
	}
}
finally {
	$zipArchive.Dispose()
}

Remove-Item -LiteralPath $stageRoot -Recurse -Force

Write-Host "Built archive: $zipPath"