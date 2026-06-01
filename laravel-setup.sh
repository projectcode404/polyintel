#!/usr/bin/env bash
# =============================================================================
# laravel-setup.sh
#
# Install Laravel 12 fresh ke folder ./laravel menggunakan Docker.
# Tidak butuh PHP/Composer di host — semua jalan via container sementara.
#
# Yang dilakukan script ini:
#   1. Install Laravel 12 via Composer (container sementara)
#   2. Copy migration files dari Sprint 1
#   3. Setup .env Laravel
#   4. Generate APP_KEY
#   5. php artisan migrate --fake (tabel sudah ada, hanya update tabel migrations)
#   6. php artisan optimize
#   7. Build dan start laravel service di docker-compose
#
# Jalankan dari root project (~/polyintel):
#   chmod +x laravel-setup.sh
#   ./laravel-setup.sh
# =============================================================================

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()    { echo -e "${GREEN}[INFO]${NC}  $*"; }
log_warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $*"; }
log_section() { echo -e "\n${BLUE}=== $* ===${NC}"; }

# Pastikan di root project
if [ ! -f "docker-compose.yml" ]; then
    log_error "Jalankan dari root project (folder yang berisi docker-compose.yml)"
    exit 1
fi

# Pastikan .env ada
if [ ! -f ".env" ]; then
    log_error ".env tidak ditemukan. Copy dari .env.example dan isi dulu."
    exit 1
fi

source .env 2>/dev/null || true

log_section "1. Install Laravel 12 via Docker"

if [ -f "laravel/artisan" ]; then
    log_warn "Laravel sudah terinstall di ./laravel — skip install"
else
    log_info "Installing Laravel 12..."

    # Gunakan container PHP sementara untuk install via Composer
    docker run --rm \
        -v "$(pwd)/laravel:/app" \
        -w /tmp \
        composer:latest \
        composer create-project laravel/laravel:^12.0 /app --prefer-dist --no-interaction

    log_info "Laravel 12 installed"
fi

log_section "2. Copy Migration Files"

MIGRATION_DIR="laravel/database/migrations"

# Cek apakah migration files sudah ada
EXISTING=$(find "$MIGRATION_DIR" -name "*.php" 2>/dev/null | grep -c "create_markets\|create_market_snapshots\|create_market_outcomes\|create_ai_predictions\|create_signals\|create_paper_trades" || true)

if [ "$EXISTING" -ge 6 ]; then
    log_info "Migration files sudah ada ($EXISTING files) — skip copy"
else
    log_warn "Migration files belum ada — perlu copy manual dari Sprint 1 output"
    log_warn "Copy files berikut ke laravel/database/migrations/:"
    log_warn "  2024_01_01_000001_create_markets_table.php"
    log_warn "  2024_01_01_000002_create_market_snapshots_table.php"
    log_warn "  2024_01_01_000003_create_market_outcomes_table.php"
    log_warn "  2024_01_01_000004_create_ai_predictions_table.php"
    log_warn "  2024_01_01_000005_create_signals_table.php"
    log_warn "  2024_01_01_000006_create_paper_trades_table.php"
fi

log_section "3. Setup Laravel .env"

LARAVEL_ENV="laravel/.env"

# Buat laravel/.env dari root .env + Laravel-specific values
cat > "$LARAVEL_ENV" << EOF
APP_NAME="Polymarket Intelligence"
APP_ENV=${APP_ENV:-production}
APP_KEY=
APP_DEBUG=${APP_DEBUG:-false}
APP_URL=http://$(curl -s ifconfig.me 2>/dev/null || echo "localhost"):8088
APP_TIMEZONE=UTC
APP_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug
LOG_DEPRECATIONS_CHANNEL=null

DB_CONNECTION=pgsql
DB_HOST=${DB_HOST:-postgres_db}
DB_PORT=${DB_PORT:-5432}
DB_DATABASE=${DB_DATABASE:-polyintel}
DB_USERNAME=${DB_USERNAME:-sikapiapsby}
DB_PASSWORD=${DB_PASSWORD}

CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=${REDIS_HOST:-redis_cache}
REDIS_PASSWORD=null
REDIS_PORT=${REDIS_PORT:-6379}

MAIL_MAILER=log

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
EOF

log_info "laravel/.env created"

log_section "4. Generate APP_KEY"

# Generate APP_KEY via container
APP_KEY=$(docker run --rm \
    -v "$(pwd)/laravel:/app" \
    -w /app \
    dunglas/frankenphp:latest-php8.4 \
    php artisan key:generate --show 2>/dev/null | tail -1)

if [ -n "$APP_KEY" ]; then
    sed -i "s|APP_KEY=|APP_KEY=$APP_KEY|" "$LARAVEL_ENV"
    log_info "APP_KEY generated: ${APP_KEY:0:20}..."
else
    log_warn "APP_KEY generation failed — jalankan manual: php artisan key:generate"
fi

log_section "5. Set Directory Permissions"

# Storage dan bootstrap/cache harus writable
chmod -R 775 laravel/storage laravel/bootstrap/cache 2>/dev/null || true
log_info "Permissions set"

log_section "6. Run migrate --fake"

log_info "Running php artisan migrate --fake..."
log_info "Ini memberitahu Laravel tabel sudah ada, hanya update tabel migrations"

docker run --rm \
    -v "$(pwd)/laravel:/app" \
    -w /app \
    --network sikap-app_sikap-network \
    --env-file "$LARAVEL_ENV" \
    dunglas/frankenphp:latest-php8.4 \
    php artisan migrate --fake --force

log_info "migrate --fake selesai"

log_section "7. Optimize Laravel"

docker run --rm \
    -v "$(pwd)/laravel:/app" \
    -w /app \
    dunglas/frankenphp:latest-php8.4 \
    php artisan optimize

log_info "Laravel optimized"

log_section "8. Start Laravel Service"

docker compose up -d laravel
sleep 5

log_section "9. Verify"

STATUS=$(docker compose ps laravel --format json 2>/dev/null | \
    python3 -c "import sys,json; d=json.load(sys.stdin); print(d[0].get('State','unknown'))" 2>/dev/null || echo "unknown")

if [ "$STATUS" = "running" ]; then
    log_info "Laravel container: RUNNING ✓"
else
    log_warn "Laravel container status: $STATUS"
    log_warn "Check: docker compose logs laravel"
fi

echo ""
log_info "Laravel dashboard tersedia di:"
IP=$(curl -s ifconfig.me 2>/dev/null || echo "IP_VPS")
echo -e "  ${GREEN}http://${IP}:8088${NC}"
echo ""
echo "Commands:"
echo "  docker compose logs -f laravel"
echo "  docker compose exec laravel php artisan migrate:status"
echo ""
