# First-time install for the Docker stack.
#
#   1. Generates .env with random DB password (if missing)
#   2. Generates cfg.php from cfg.sample.php with Docker-friendly defaults (if missing)
#   3. Builds image (if not built)
#   4. Brings the stack up
#   5. Waits for DB health and runs migrations
#   6. Prints the URL where the setup wizard is available
#
# Idempotent — safe to re-run.
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "'docker compose' (v2) plugin required — install Docker Desktop"
}

function New-RandomToken([int]$Bytes = 24) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([Convert]::ToBase64String($buf) -replace '[+/=]', '').Substring(0, [math]::Min($Bytes + 4, 32))
}

# --- 1. .env --------------------------------------------------------------
if (-not (Test-Path .env)) {
    Write-Host "==> Generating .env with random DB passwords…"
    $rootPass = New-RandomToken 24
    $userPass = New-RandomToken 24
    @"
# MyInvoice.cz - Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myinvoice
DB_USER=myinvoice
DB_ROOT_PASSWORD=$rootPass
DB_PASSWORD=$userPass
"@ | Set-Content -Encoding UTF8 -NoNewline .env
    Write-Host "    .env written (passwords randomised)"
} else {
    Write-Host "==> .env already exists (skipping)"
}

# Load .env into local hashtable
$envVars = @{}
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2] }
}

# --- 2. cfg.docker.php ----------------------------------------------------
# Separate from cfg.php so the same checkout can run both native dev (`php -S`)
# and the Docker stack without one clobbering the other. compose mounts this
# file as /var/www/html/cfg.php inside the container.
if (-not (Test-Path cfg.docker.php)) {
    Write-Host "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
    $pepper = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
    $encKey = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
    $cfg = Get-Content cfg.sample.php -Raw
    # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db then redis).
    # Replace first occurrence -> db, then first remaining occurrence -> redis.
    $appUrl = "http://localhost:$($envVars.APP_PORT)"
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'db',",    1)
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'redis',", 1)
    $cfg = $cfg -replace "'name'    => 'myinvoice',", "'name'    => '$($envVars.DB_NAME)',"
    $cfg = $cfg -replace "'user'    => 'root',",      "'user'    => '$($envVars.DB_USER)',"
    $cfg = $cfg -replace "'pass'    => 'CHANGE-ME',", "'pass'    => '$($envVars.DB_PASSWORD)',"
    $cfg = $cfg -replace "'pepper' => 'CHANGE-ME',",  "'pepper' => '$pepper',"
    $cfg = $cfg -replace "'secret_encryption_key' => '',", "'secret_encryption_key' => '$encKey',"
    $cfg = $cfg -replace "'env'    => 'production',",      "'env'    => 'development',"
    $cfg = $cfg -replace "'url'    => 'https://dev\.example\.com',", "'url'    => '$appUrl',"
    $cfg = $cfg -replace "'cookie_name'   => '__Host-myinvoice_session',", "'cookie_name'   => 'myinvoice_session',"
    $cfg = $cfg -replace "'cookie_secure' => true,", "'cookie_secure' => false,"
    Set-Content -Encoding UTF8 -NoNewline cfg.docker.php -Value $cfg
    Write-Host "    cfg.docker.php written"
    Write-Host ""
    Write-Host "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!" -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host "==> cfg.docker.php already exists (checking Docker hostnames)"
    $cfg = Get-Content cfg.docker.php -Raw
    if ($cfg -match "'host'    => '127\.0\.0\.1',") {
        $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'db',",    1)
        $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'redis',", 1)
        Set-Content -Encoding UTF8 -NoNewline cfg.docker.php -Value $cfg
        Write-Host "    cfg.docker.php hostnames updated for Docker"
    }
}

# --- 3. build --------------------------------------------------------------
$imgExists = (& docker image inspect myinvoice:latest 2>$null)
if ($LASTEXITCODE -ne 0) {
    Write-Host "==> Building image…"
    & docker compose build app
    if ($LASTEXITCODE -ne 0) { Write-Error "docker compose build failed" }
}

# --- 4. up -----------------------------------------------------------------
Write-Host "==> Starting stack…"
& docker compose up -d db app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up failed" }

# --- 5. wait for DB + migrate ---------------------------------------------
Write-Host "==> Waiting for database to become healthy…"
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $json = & docker compose ps --format json db 2>$null
    if ($json -match '"Health":"healthy"') { $ready = $true; Write-Host "    DB ready."; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "DB failed to become healthy in 60s. Check 'docker compose logs db'."
}

Write-Host "==> Running database migrations…"
& docker compose exec -T app php api/bin/migrate.php
if ($LASTEXITCODE -ne 0) { Write-Error "Migrations failed" }

# --- 6. report -------------------------------------------------------------
$port = $envVars.APP_PORT
if (-not $port) { $port = '8080' }
Write-Host ""
Write-Host "============================================================"
Write-Host " MyInvoice.cz is up at:  http://localhost:$port"
Write-Host ""
Write-Host " The browser will land on the setup wizard:"
Write-Host "   1. Admin user (name, email, password >= 12 chars)"
Write-Host "   2. Supplier (IC -> Nacist z ARES -> bank account)"
Write-Host "   3. Optional sample data"
Write-Host ""
Write-Host " Useful:"
Write-Host "   docker compose logs -f app    # tail app logs"
Write-Host "   docker compose down           # stop stack (data persists)"
Write-Host "   docker compose down -v        # stop + WIPE volumes (destroys DB)"
Write-Host "============================================================"
