<#
.SYNOPSIS
  Starts a multi-worker local Laravel dev server on Windows (eliminates single-threaded `php artisan serve` FIFO blocking).

.EXAMPLE
  .\serve_multiworker.ps1
  .\serve_multiworker.ps1 -Port 8000 -Workers 6
#>
param(
    [string]$BindHost = "127.0.0.1",
    [int]$Port = 8000,
    [int]$Workers = 4
)

$ErrorActionPreference = "Stop"
$BackendDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# 1. Prefer FrankenPHP if installed (native multi-threaded PHP server)
$franken = Get-Command frankenphp -ErrorAction SilentlyContinue
if ($franken) {
    Write-Host "[RetailPOS] Launching FrankenPHP multi-threaded server on http://${BindHost}:${Port} ..." -ForegroundColor Green
    Set-Location $BackendDir
    & $franken.Source php-server --listen "${BindHost}:${Port}" --root public --threads $Workers
    exit $LASTEXITCODE
}

# 2. Fallback: Zero-dependency Node.js least-connections proxy + N parallel php.exe workers
$node = Get-Command node -ErrorAction SilentlyContinue
$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $node -or -not $php) {
    Write-Error "Both 'node' and 'php' must be available on PATH to run multiworker_proxy.mjs."
    exit 1
}

$env:HOST = $BindHost
$env:PORT = "$Port"
$env:WORKERS = "$Workers"
$env:PHP_BIN = $php.Source

Write-Host "[RetailPOS] Launching ${Workers}-worker parallel Laravel dev pool on http://${BindHost}:${Port} ..." -ForegroundColor Cyan
& $node.Source (Join-Path $BackendDir "scripts\multiworker_proxy.mjs")
exit $LASTEXITCODE
