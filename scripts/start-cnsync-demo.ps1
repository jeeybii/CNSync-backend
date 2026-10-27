[CmdletBinding()]
param(
    [string]$FrontendPath,
    [int]$BackendPort = 8888,
    [switch]$DeployVercel
)

$ErrorActionPreference = 'Stop'

$backendPath = (Split-Path $PSScriptRoot -Parent)
if ([string]::IsNullOrWhiteSpace($FrontendPath)) {
    $FrontendPath = Join-Path $backendPath '..\cnsync-fe'
}
$frontendPath = [System.IO.Path]::GetFullPath($FrontendPath)
$envPath = Join-Path $backendPath '.env'
$runtimePath = Join-Path $backendPath 'storage\logs\demo-runtime'

function Assert-Command {
    param([Parameter(Mandatory)][string]$Name)

    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Required command [$Name] was not found. Install it and open a new PowerShell window."
    }
}

function ConvertTo-PowerShellLiteral {
    param([Parameter(Mandatory)][string]$Value)

    return "'" + $Value.Replace("'", "''") + "'"
}

function Start-CnsyncWindow {
    param(
        [Parameter(Mandatory)][string]$Title,
        [Parameter(Mandatory)][string]$Command
    )

    $location = ConvertTo-PowerShellLiteral $backendPath
    $windowCommand = "`$host.UI.RawUI.WindowTitle = '$Title'; Set-Location -LiteralPath $location; $Command"
    Start-Process powershell.exe -ArgumentList @('-NoExit', '-Command', $windowCommand) | Out-Null
}

function Test-Backend {
    try {
        Invoke-WebRequest "http://127.0.0.1:$BackendPort/up" -UseBasicParsing -TimeoutSec 2 | Out-Null
        return $true
    } catch {
        return $false
    }
}

function Test-WorkerRunning {
    param([Parameter(Mandatory)][string]$Queue)

    $needle = "queue:work --queue=$Queue"
    return [bool](Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like "*$needle*" } |
        Select-Object -First 1)
}

function Set-DotEnvValue {
    param(
        [Parameter(Mandatory)][string]$Name,
        [Parameter(Mandatory)][string]$Value
    )

    $content = [System.IO.File]::ReadAllText($envPath)
    $replacement = "$Name=$Value"
    $pattern = "(?m)^" + [regex]::Escape($Name) + "=.*$"

    if ([regex]::IsMatch($content, $pattern)) {
        $content = [regex]::Replace($content, $pattern, $replacement)
    } else {
        $content = $content.TrimEnd("`r", "`n") + "`r`n$replacement`r`n"
    }

    $utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($envPath, $content, $utf8WithoutBom)
}

function Get-DotEnvValue {
    param([Parameter(Mandatory)][string]$Name)

    $content = [System.IO.File]::ReadAllText($envPath)
    $match = [regex]::Match($content, "(?m)^" + [regex]::Escape($Name) + "=(.*)$")
    if (-not $match.Success) {
        return $null
    }

    return $match.Groups[1].Value.Trim().Trim('"').Trim("'")
}

function Start-QuickTunnel {
    $existing = Get-CimInstance Win32_Process -Filter "Name = 'cloudflared.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like '*tunnel*--url*' } |
        Select-Object -First 1

    if ($existing) {
        throw 'A Cloudflare Quick Tunnel is already running. Keep using its URL, or stop that cloudflared process before running this launcher so the new URL can be captured.'
    }

    New-Item -ItemType Directory -Path $runtimePath -Force | Out-Null
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $stdoutPath = Join-Path $runtimePath "cloudflared-$stamp.out.log"
    $stderrPath = Join-Path $runtimePath "cloudflared-$stamp.err.log"
    $arguments = @('tunnel', '--url', "http://127.0.0.1:$BackendPort")
    $process = Start-Process cloudflared.exe -ArgumentList $arguments -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath -PassThru

    $deadline = (Get-Date).AddSeconds(60)
    do {
        Start-Sleep -Milliseconds 500
        $text = ''
        if (Test-Path $stdoutPath) { $text += [System.IO.File]::ReadAllText($stdoutPath) }
        if (Test-Path $stderrPath) { $text += [System.IO.File]::ReadAllText($stderrPath) }
        $match = [regex]::Match($text, 'https://[a-z0-9-]+\.trycloudflare\.com')
        if ($match.Success) {
            return [pscustomobject]@{
                Process = $process
                Url = $match.Value
                Stdout = $stdoutPath
                Stderr = $stderrPath
            }
        }
    } while ((Get-Date) -lt $deadline -and -not $process.HasExited)

    if (-not $process.HasExited) {
        Stop-Process -Id $process.Id -ErrorAction SilentlyContinue
    }
    throw "Cloudflare did not provide a Quick Tunnel URL. Check $stderrPath."
}

function Deploy-VercelFrontend {
    param([Parameter(Mandatory)][string]$ApiUrl)

    if (-not (Test-Path (Join-Path $frontendPath 'package.json'))) {
        throw "Frontend package.json was not found at [$frontendPath]."
    }
    if (-not (Test-Path (Join-Path $frontendPath '.vercel\project.json'))) {
        throw "The frontend is not linked to Vercel. Run [npx vercel@latest] once from $frontendPath."
    }

    Write-Host 'Updating Vercel production VITE_API_URL...'
    $ApiUrl | & npx.cmd vercel@latest env update VITE_API_URL production --yes --cwd $frontendPath
    if ($LASTEXITCODE -ne 0) {
        throw 'Vercel environment update failed. Confirm that Vercel CLI is logged in.'
    }

    $deployPath = Join-Path $env:TEMP ("cnsync-vercel-" + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    New-Item -ItemType Directory -Path $deployPath | Out-Null
    & robocopy.exe $frontendPath $deployPath /E /XD .git node_modules dist .vercel | Out-Null
    if ($LASTEXITCODE -gt 7) {
        throw "Could not prepare the clean Vercel deployment copy. Robocopy exit code: $LASTEXITCODE"
    }
    Copy-Item (Join-Path $frontendPath '.vercel') (Join-Path $deployPath '.vercel') -Recurse

    Push-Location $deployPath
    try {
        Write-Host 'Installing frontend dependencies in the temporary deployment copy...'
        & npm.cmd install
        if ($LASTEXITCODE -ne 0) { throw 'npm install failed.' }

        Write-Host 'Deploying the frontend to Vercel production...'
        & npx.cmd vercel@latest --prod --yes --build-env "VITE_API_URL=$ApiUrl"
        if ($LASTEXITCODE -ne 0) { throw 'Vercel production deployment failed.' }
    } finally {
        Pop-Location
    }
}

Assert-Command 'php'
Assert-Command 'cloudflared.exe'
if (-not (Test-Path $envPath)) { throw "Backend .env was not found at [$envPath]." }

Push-Location $backendPath
try {
    Write-Host 'Checking the database connection...'
    & php artisan migrate:status --no-ansi | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw 'Laravel could not reach MySQL. Start MySQL and verify the DB_* values in .env.'
    }

    $ollamaUrl = Get-DotEnvValue 'OLLAMA_BASE_URL'
    if ($ollamaUrl) {
        try {
            Invoke-RestMethod "$($ollamaUrl.TrimEnd('/'))/api/tags" -TimeoutSec 10 | Out-Null
            Write-Host "Ollama is reachable at $ollamaUrl" -ForegroundColor Green
        } catch {
            Write-Warning "Ollama is not reachable at $ollamaUrl. The app can start, but local exam generation will fail until Ollama and Tailscale are available."
        }
    } else {
        Write-Warning 'OLLAMA_BASE_URL is not configured in the backend .env.'
    }

    if (-not (Test-Backend)) {
        Write-Host 'Starting Laravel...'
        Start-CnsyncWindow 'CNSync - Laravel API' "php -S localhost:$BackendPort -t public"
        $deadline = (Get-Date).AddSeconds(30)
        do { Start-Sleep -Seconds 1 } while (-not (Test-Backend) -and (Get-Date) -lt $deadline)
        if (-not (Test-Backend)) { throw "Laravel did not become ready on port $BackendPort." }
    } else {
        Write-Host "Laravel is already running on port $BackendPort."
    }

    if (-not (Test-WorkerRunning 'documents,default')) {
        Write-Host 'Starting the document worker...'
        Start-CnsyncWindow 'CNSync - Documents Queue' 'php artisan queue:work --queue=documents,default --tries=3 --timeout=0 --sleep=1'
    } else {
        Write-Host 'The document worker is already running.'
    }

    if (-not (Test-WorkerRunning 'generation')) {
        Write-Host 'Starting the generation worker...'
        Start-CnsyncWindow 'CNSync - Generation Queue' 'php artisan queue:work --queue=generation --tries=3 --timeout=0 --sleep=1'
    } else {
        Write-Host 'The generation worker is already running.'
    }

    Write-Host 'Creating a Cloudflare Quick Tunnel...'
    $tunnel = Start-QuickTunnel
    $apiUrl = "$($tunnel.Url)/api"
    Set-DotEnvValue 'APP_URL' $tunnel.Url
    & php artisan optimize:clear --no-ansi | Out-Null

    $runtimeState = [pscustomobject]@{
        tunnel_url = $tunnel.Url
        api_url = $apiUrl
        cloudflared_pid = $tunnel.Process.Id
        started_at = (Get-Date).ToString('o')
        stderr_log = $tunnel.Stderr
    }
    $runtimeState | ConvertTo-Json | Set-Content (Join-Path $runtimePath 'current.json') -Encoding UTF8

    Write-Host "Cloudflare URL: $($tunnel.Url)" -ForegroundColor Green
    Write-Host "Vercel API URL: $apiUrl" -ForegroundColor Green

    if ($DeployVercel) {
        Assert-Command 'node.exe'
        Assert-Command 'npm.cmd'
        Assert-Command 'npx.cmd'
        Deploy-VercelFrontend $apiUrl
    } else {
        Write-Host ''
        Write-Host 'The backend is ready. To also update and deploy Vercel, rerun with:' -ForegroundColor Yellow
        Write-Host '  powershell -ExecutionPolicy Bypass -File .\scripts\start-cnsync-demo.ps1 -DeployVercel'
        Write-Host 'Stop the Cloudflare process from this run first, because each run creates a new Quick Tunnel.'
    }
} finally {
    Pop-Location
}
