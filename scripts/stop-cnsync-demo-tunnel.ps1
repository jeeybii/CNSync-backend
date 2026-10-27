[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$backendPath = Split-Path $PSScriptRoot -Parent
$statePath = Join-Path $backendPath 'storage\logs\demo-runtime\current.json'

if (-not (Test-Path $statePath)) {
    Write-Host 'No launcher-managed Cloudflare Tunnel state was found.'
    exit 0
}

$state = Get-Content $statePath -Raw | ConvertFrom-Json
$process = Get-CimInstance Win32_Process -Filter "ProcessId = $($state.cloudflared_pid)" -ErrorAction SilentlyContinue

if (-not $process) {
    Write-Host 'The recorded Cloudflare Tunnel is no longer running.'
    Remove-Item $statePath
    exit 0
}

if ($process.Name -ne 'cloudflared.exe' -or $process.CommandLine -notlike '*tunnel*--url*') {
    throw "Process $($state.cloudflared_pid) is not the expected Cloudflare Tunnel. It was not stopped."
}

Stop-Process -Id $state.cloudflared_pid
Remove-Item $statePath
Write-Host "Stopped Cloudflare Tunnel $($state.tunnel_url)."
