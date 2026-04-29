param(
	[string]$MainFile = 'wp-hosting-benchmark.php',
	[string]$ReadmeFile = 'readme.txt',
	[ValidateSet('major', 'minor', 'patch')]
	[string]$VersionPart = 'patch',
	[string]$NewVersion = ''
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$mainFilePath = Join-Path $repoRoot $MainFile
$readmeFilePath = Join-Path $repoRoot $ReadmeFile

if (-not (Test-Path -LiteralPath $mainFilePath)) {
	throw "Main plugin file not found: $mainFilePath"
}

$mainFileContent = Get-Content -LiteralPath $mainFilePath -Raw
$versionMatch = [regex]::Match($mainFileContent, '(?m)^(\s*\*\s*Version:\s*)(\d+\.\d+\.\d+)(\s*)$')

if (-not $versionMatch.Success) {
	throw "Could not determine plugin version from $MainFile"
}

$currentVersion = $versionMatch.Groups[2].Value

if ([string]::IsNullOrWhiteSpace($NewVersion)) {
	$parts = $currentVersion.Split('.') | ForEach-Object { [int]$_ }

	switch ($VersionPart) {
		'major' {
			$parts[0]++
			$parts[1] = 0
			$parts[2] = 0
		}
		'minor' {
			$parts[1]++
			$parts[2] = 0
		}
		default {
			$parts[2]++
		}
	}

	$NewVersion = $parts -join '.'
}

if ($NewVersion -notmatch '^\d+\.\d+\.\d+$') {
	throw "Version must use semantic version format: MAJOR.MINOR.PATCH"
}

$mainFileContent = [regex]::Replace(
	$mainFileContent,
	'(?m)^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+(\s*)$',
	"`${1}$NewVersion`${2}"
)

$mainFileContent = [regex]::Replace(
	$mainFileContent,
	"(?m)^(define\(\s*'WP_HOSTING_BENCHMARK_VERSION'\s*,\s*')\d+\.\d+\.\d+('\s*\);)$",
	"`${1}$NewVersion`${2}"
)

Set-Content -LiteralPath $mainFilePath -Value $mainFileContent -NoNewline

if (Test-Path -LiteralPath $readmeFilePath) {
	$readmeContent = Get-Content -LiteralPath $readmeFilePath -Raw
	$readmeContent = [regex]::Replace(
		$readmeContent,
		'(?m)^(Stable tag:\s*)\d+\.\d+\.\d+(\s*)$',
		"`${1}$NewVersion`${2}"
	)
	Set-Content -LiteralPath $readmeFilePath -Value $readmeContent -NoNewline
}

Write-Host "Version bumped: $currentVersion -> $NewVersion"
