# Create junction so procest/openspec/schemas points to shared apps-extra/openspec/schemas
# Run from procest root: .\openspec\setup-schemas.ps1

$schemasDir = Join-Path $PSScriptRoot "schemas"
$targetDir = (Resolve-Path (Join-Path $PSScriptRoot "..\..\openspec\schemas") -ErrorAction SilentlyContinue)

if (-not $targetDir) {
    Write-Error "Shared schemas not found at apps-extra/openspec/schemas. Ensure nextcloud-docker-dev workspace is set up."
    exit 1
}

if (Test-Path $schemasDir) {
    $item = Get-Item $schemasDir
    if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        Write-Host "Junction already exists: $schemasDir"
        exit 0
    }
    Remove-Item -Recurse -Force $schemasDir
}

cmd /c mklink /J $schemasDir $targetDir.Path
Write-Host "Created junction: $schemasDir -> $($targetDir.Path)"
