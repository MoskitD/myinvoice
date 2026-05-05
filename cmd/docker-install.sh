#!/usr/bin/env bash
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
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi

# --- 1. .env ---------------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "==> Generating .env with random DB password…"
  DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 28)
  DB_PASSWORD=$(openssl rand -base64 24      | tr -d '=+/' | head -c 28)
  cat > .env <<EOF
# MyInvoice.cz — Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myinvoice
DB_USER=myinvoice
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PASSWORD=${DB_PASSWORD}
EOF
  echo "    .env written (passwords randomised)"
else
  echo "==> .env already exists (skipping)"
fi
# Load .env so the rest of the script sees the values
set -a; . ./.env; set +a

# --- 2. cfg.docker.php -----------------------------------------------------
# Separate from cfg.php so the same checkout can run both native dev (`php -S`)
# and the Docker stack without one clobbering the other. compose mounts this
# file as /var/www/html/cfg.php inside the container.
if [[ ! -f cfg.docker.php ]]; then
  echo "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
  PEPPER=$(openssl rand -base64 32)
  ENC_KEY=$(openssl rand -base64 32)
  cp cfg.sample.php cfg.docker.php
  # In-place tweaks: Docker hostnames + generated secrets.
  # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db block then redis block);
  # replace the first occurrence, then the first remaining occurrence. The `1,/pat/`
  # range works with both GNU sed and BSD/macOS sed.
  APP_URL="http://localhost:${APP_PORT}"
  sed -i.bak \
      -e "1,/'host'    => '127\.0\.0\.1',/s|'host'    => '127\.0\.0\.1',|'host'    => 'db',|" \
      -e "1,/'host'    => '127\.0\.0\.1',/s|'host'    => '127\.0\.0\.1',|'host'    => 'redis',|" \
      -e "s|'name'    => 'myinvoice',|'name'    => '${DB_NAME}',|" \
      -e "s|'user'    => 'root',|'user'    => '${DB_USER}',|" \
      -e "s|'pass'    => 'CHANGE-ME',|'pass'    => '${DB_PASSWORD}',|" \
      -e "s|'pepper' => 'CHANGE-ME',|'pepper' => '${PEPPER}',|" \
      -e "s|'secret_encryption_key' => '',|'secret_encryption_key' => '${ENC_KEY}',|" \
      -e "s|'env'    => 'production',|'env'    => 'development',|" \
      -e "s|'url'    => 'https://dev.example.com',|'url'    => '${APP_URL}',|" \
      -e "s|'cookie_name'   => '__Host-myinvoice_session',|'cookie_name'   => 'myinvoice_session',|" \
      -e "s|'cookie_secure' => true,|'cookie_secure' => false,|" \
      cfg.docker.php
  rm -f cfg.docker.php.bak
  echo "    cfg.docker.php written"
  echo ""
  echo "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!"
  echo ""
else
  echo "==> cfg.docker.php already exists (checking Docker hostnames)"
  if grep -q "'host'    => '127.0.0.1'," cfg.docker.php; then
    sed -i.bak \
        -e "1,/'host'    => '127\.0\.0\.1',/s|'host'    => '127\.0\.0\.1',|'host'    => 'db',|" \
        -e "1,/'host'    => '127\.0\.0\.1',/s|'host'    => '127\.0\.0\.1',|'host'    => 'redis',|" \
        cfg.docker.php
    rm -f cfg.docker.php.bak
    echo "    cfg.docker.php hostnames updated for Docker"
  fi
fi

# --- 3. build --------------------------------------------------------------
if ! docker image inspect myinvoice:latest >/dev/null 2>&1; then
  echo "==> Building image…"
  docker compose build app
fi

# --- 4. up -----------------------------------------------------------------
echo "==> Starting stack…"
docker compose up -d db app

# --- 5. wait for DB + migrate ---------------------------------------------
echo "==> Waiting for database to become healthy…"
for i in {1..30}; do
  status=$(docker compose ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | head -1 | cut -d'"' -f4)
  if [[ "$status" == "healthy" ]]; then echo "    DB ready."; break; fi
  sleep 2
  if [[ $i -eq 30 ]]; then
    echo "ERROR: DB failed to become healthy in 60s. Check 'docker compose logs db'." >&2
    exit 1
  fi
done

echo "==> Running database migrations…"
docker compose exec -T app php api/bin/migrate.php

# --- 6. report -------------------------------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo ""
echo "============================================================"
echo " MyInvoice.cz is up at:  http://localhost:${APP_PORT}"
echo ""
echo " The browser will land on the setup wizard:"
echo "   1. Admin user (name, email, password ≥ 12 chars)"
echo "   2. Supplier (IČ → Načíst z ARES → bank account)"
echo "   3. Optional sample data"
echo ""
echo " Useful:"
echo "   docker compose logs -f app    # tail app logs"
echo "   docker compose down           # stop stack (data persists)"
echo "   docker compose down -v        # stop + WIPE volumes (destroys DB)"
echo "============================================================"
