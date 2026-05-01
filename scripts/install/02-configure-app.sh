#!/usr/bin/env bash
#
# FreenetIS Laravel — fáze 2: deploy aplikace + konfigurace.
# Interaktivní — ptá se na doménu, DB, secrets.
#
# Předpoklady:
#   - 01-install-system.sh už proběhl
#   - běží jako root (sudo)
#
# Co dělá:
#   1. Naclonuje / updatuje repozitář do /var/www/html/freenetis-laravel
#   2. composer install --no-dev
#   3. Vyžádá vstupy (doména, DB, mail, ...)
#   4. Vygeneruje .env s náhodnými secrets (APP_KEY, OTP_PEPPER, CONTRACTS_TOKEN_SECRET)
#   5. Vytvoří DB + uživatele
#   6. Importuje SQL dump (pokud byl zadán) NEBO spustí migrace
#   7. Spustí ACL seedery
#   8. Vytvoří storage adresáře + permissions
#   9. Aktivuje Apache vhost
#  10. Nastaví cron pro schedule:run
#  11. Spustí certbot pro Let's Encrypt
#
# Co NEDĚLÁ (manuálně doplň po skriptu):
#   - PFX certifikát pro digitální podpis smluv
#   - FIO API tokeny per účet (admin → Bankovní účty → token)
#   - Úprava SMS_API_KEY pokud přijde od provozu jiný klíč
#   - Externí PDF přílohy (cenik.pdf, vop.pdf)

set -euo pipefail

# ── Logger ───────────────────────────────────────────────────────────────────
log()  { printf '\033[1;34m[*]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[+]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Spusť jako root (sudo bash $0)"

# ── 1. Cesty ─────────────────────────────────────────────────────────────────
APP_DIR="/var/www/html/freenetis-laravel"
REPO_URL="${REPO_URL:-https://github.com/erotel/freenetis-laravel.git}"
REPO_BRANCH="${REPO_BRANCH:-laravel-migration}"
TEMPLATE_DIR="$(dirname "$(readlink -f "$0")")/templates"

# Detekuj PHP verzi (Debian 13 = 8.4, Ubuntu 24.04 = 8.3)
if command -v php8.4 &>/dev/null; then PHPV="8.4"
elif command -v php8.3 &>/dev/null; then PHPV="8.3"
else die "PHP 8.3/8.4 nenalezeno. Spusť 01-install-system.sh nejdřív."
fi
log "Použiji PHP $PHPV"

# ── 2. Interaktivní vstupy ───────────────────────────────────────────────────
echo
echo "═══════════════════════════════════════════"
echo "  Konfigurace FreenetIS Laravel"
echo "═══════════════════════════════════════════"
echo

ask() {
    local prompt="$1" default="${2:-}" var
    if [[ -n "$default" ]]; then
        read -r -p "$prompt [$default]: " var
        echo "${var:-$default}"
    else
        read -r -p "$prompt: " var
        echo "$var"
    fi
}
ask_secret() {
    local prompt="$1" var
    read -rs -p "$prompt: " var
    echo >&2
    echo "$var"
}

DOMAIN="$(ask "Doména (FQDN, např. is.pvfree.net)")"
[[ -n "$DOMAIN" ]] || die "Doména je povinná"

CERTBOT_EMAIL="$(ask "E-mail pro Let's Encrypt (notifikace expirace)" "admin@$DOMAIN")"

DB_NAME="$(ask "DB jméno" "freenetis")"
DB_USER="$(ask "DB uživatel" "freenetis")"
DB_PASS="$(ask_secret "DB heslo (Enter = vygeneruj náhodné)")"
[[ -z "$DB_PASS" ]] && DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"

CONTRACTS_DB_NAME="$(ask "Contracts DB jméno (separátní DB pro smlouvy)" "contractsdb")"
CONTRACTS_DB_USER="$(ask "Contracts DB uživatel" "contracts")"
CONTRACTS_DB_PASS="$(ask_secret "Contracts DB heslo (Enter = vygeneruj)")"
[[ -z "$CONTRACTS_DB_PASS" ]] && CONTRACTS_DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"

DUMP_PATH="$(ask "Cesta k freenetis SQL dumpu (prázdné = jen migrace)" "")"
CONTRACTS_DUMP_PATH="$(ask "Cesta k contractsdb SQL dumpu (prázdné = jen migrace)" "")"

echo
echo "── E-mail (SMTP pro odchozí poštu) ────────"
MAIL_HOST="$(ask "SMTP host" "smtp.gmail.com")"
MAIL_PORT="$(ask "SMTP port" "587")"
MAIL_USER="$(ask "SMTP user" "")"
MAIL_PASS="$(ask_secret "SMTP heslo")"
MAIL_FROM="$(ask "Odesílatel (from)" "noreply@$DOMAIN")"

echo
echo "── SMS (SmsManager) ───────────────────────"
SMS_API_KEY="$(ask_secret "SMS_API_KEY (nech prázdné, vyplníš později)")"
SMS_SENDER="$(ask "SMS_SENDER (case-sensitive!)" "PVfree")"

# ── 3. Souhrn ────────────────────────────────────────────────────────────────
echo
echo "═══════════════════════════════════════════"
echo "  Souhrn před akcí"
echo "═══════════════════════════════════════════"
cat <<EOF
  Doména:               https://$DOMAIN/freenetis
  PHP:                  $PHPV
  Aplikace:             $APP_DIR
  Repo:                 $REPO_URL ($REPO_BRANCH)
  DB main:              $DB_NAME (user $DB_USER)
  DB contracts:         $CONTRACTS_DB_NAME (user $CONTRACTS_DB_USER)
  Dump main:            ${DUMP_PATH:-(žádný — migrace)}
  Dump contracts:       ${CONTRACTS_DUMP_PATH:-(žádný — migrace)}
  Mail:                 $MAIL_USER@$MAIL_HOST:$MAIL_PORT
  SMS sender:           $SMS_SENDER
  Let's Encrypt e-mail: $CERTBOT_EMAIL
EOF
read -r -p "Pokračovat? [y/N] " confirm
[[ "$confirm" =~ ^[yY]$ ]] || die "Zrušeno uživatelem"

# ── 4. Klon / update repo ────────────────────────────────────────────────────
if [[ -d "$APP_DIR/.git" ]]; then
    log "Repo už existuje — pull..."
    git -C "$APP_DIR" fetch --quiet origin "$REPO_BRANCH"
    git -C "$APP_DIR" checkout --quiet "$REPO_BRANCH"
    git -C "$APP_DIR" pull --quiet --ff-only origin "$REPO_BRANCH"
else
    log "Cloning $REPO_URL..."
    mkdir -p "$(dirname "$APP_DIR")"
    git clone --quiet --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi

# ── 5. composer install ──────────────────────────────────────────────────────
log "composer install (může chvilku trvat)..."
cd "$APP_DIR"
sudo -u www-data composer install --no-dev --optimize-autoloader --quiet \
    || die "composer install selhal"

# ── 6. Vygeneruj secrets ─────────────────────────────────────────────────────
APP_KEY="$(php"$PHPV" -r 'echo "base64:".base64_encode(random_bytes(32));')"
OTP_PEPPER="$(openssl rand -hex 32)"
CONTRACTS_TOKEN_SECRET="$(openssl rand -hex 32)"

# ── 7. Sestav .env ───────────────────────────────────────────────────────────
log "Generuju .env..."
cat > "$APP_DIR/.env" <<EOF
APP_NAME=FreenetIS
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=https://$DOMAIN/freenetis
APP_TIMEZONE=Europe/Prague
APP_LOCALE=cs
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB_NAME
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS

CONTRACTS_DB_HOST=127.0.0.1
CONTRACTS_DB_PORT=3306
CONTRACTS_DB_DATABASE=$CONTRACTS_DB_NAME
CONTRACTS_DB_USER=$CONTRACTS_DB_USER
CONTRACTS_DB_PASS=$CONTRACTS_DB_PASS

CACHE_STORE=file
SESSION_DRIVER=file
SESSION_PATH=/freenetis
SESSION_LIFETIME=120
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=$MAIL_HOST
MAIL_PORT=$MAIL_PORT
MAIL_USERNAME=$MAIL_USER
MAIL_PASSWORD=$MAIL_PASS
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=$MAIL_FROM
MAIL_FROM_NAME=FreenetIS

# Smlouvy
CONTRACTS_TOKEN_SECRET=$CONTRACTS_TOKEN_SECRET
CONTRACTS_STORAGE=$APP_DIR/storage/app/private/contracts/signed
CONTRACTS_TMP=$APP_DIR/storage/app/private/contracts/tmp
CONTRACTS_SMLOUVY_URL=https://$DOMAIN/freenetis

# OTP / SMS
OTP_PEPPER=$OTP_PEPPER
OTP_TTL_MIN=5
OTP_MAX_ATTEMPTS=5
OTP_RESEND_WINDOW_SEC=15
OTP_TEST_MODE=false
OTP_TEST_CODE=123456

SMS_API_URL=https://app.smsmanager.cz/api
SMS_API_KEY=$SMS_API_KEY
SMS_SENDER=$SMS_SENDER

# PDF podpis (vyplň ručně až budeš mít certifikát)
PDF_SIGN_CERT=$APP_DIR/storage/app/private/cert/pvfree.pfx
PDF_SIGN_PASS=
PDF_SIGN_NAME=$SMS_SENDER
PDF_SIGN_REASON=Elektronický podpis
PDF_SIGN_LOCATION=
EOF
chmod 600 "$APP_DIR/.env"
chown www-data:www-data "$APP_DIR/.env"

# ── 8. Vytvoř DB + uživatele ─────────────────────────────────────────────────
log "Vytváří DB + uživatele..."
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`$CONTRACTS_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$CONTRACTS_DB_USER'@'localhost' IDENTIFIED BY '$CONTRACTS_DB_PASS';
ALTER USER '$CONTRACTS_DB_USER'@'localhost' IDENTIFIED BY '$CONTRACTS_DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$CONTRACTS_DB_NAME\`.* TO '$CONTRACTS_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ── 9. Import dumpů (volitelné) ──────────────────────────────────────────────
if [[ -n "$DUMP_PATH" ]]; then
    [[ -f "$DUMP_PATH" ]] || die "Dump neexistuje: $DUMP_PATH"
    log "Importuji main dump..."
    mariadb "$DB_NAME" < "$DUMP_PATH"
fi
if [[ -n "$CONTRACTS_DUMP_PATH" ]]; then
    [[ -f "$CONTRACTS_DUMP_PATH" ]] || die "Contracts dump neexistuje: $CONTRACTS_DUMP_PATH"
    log "Importuji contracts dump..."
    mariadb "$CONTRACTS_DB_NAME" < "$CONTRACTS_DUMP_PATH"
fi

# ── 10. Storage adresáře ─────────────────────────────────────────────────────
log "Vytváří storage adresáře..."
mkdir -p \
    "$APP_DIR/storage/app/private/contracts/"{signed,tmp,cert} \
    "$APP_DIR/storage/framework/"{cache,sessions,views} \
    "$APP_DIR/bootstrap/cache"
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/storage" -type d -exec chmod 775 {} \;

# ── 11. Migrace + seedery ────────────────────────────────────────────────────
cd "$APP_DIR"
log "Spouštím migrace..."
sudo -u www-data "php${PHPV}" artisan migrate --force --no-interaction || warn "migrace skončila s chybou — ověř DB schéma"

log "Spouštím ACL seedery..."
sudo -u www-data "php${PHPV}" artisan db:seed --class=AclGponContractsSeeder --force --no-interaction || true
sudo -u www-data "php${PHPV}" artisan db:seed --class=AclSmtpExceptionsSeeder --force --no-interaction || true

# Index pro email_queues (přidaný ručně mimo migrace, dokud není správná migrace)
mariadb "$DB_NAME" -e "
    SET @x := (SELECT COUNT(*) FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = 'email_queues' AND index_name = 'idx_state');
    SET @sql := IF(@x = 0, 'ALTER TABLE email_queues ADD INDEX idx_state (state)', 'SELECT 1');
    PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
" 2>/dev/null || true

# ── 12. Apache vhost ─────────────────────────────────────────────────────────
log "Aktivuji Apache vhost pro $DOMAIN..."
VHOST="/etc/apache2/sites-available/freenetis.conf"
sed "s/{{DOMAIN}}/$DOMAIN/g" "$TEMPLATE_DIR/apache-freenetis.conf" > "$VHOST"
# Patch php-fpm socket pokud Ubuntu (8.3)
if [[ "$PHPV" != "8.4" ]]; then
    sed -i "s|php8\.4-fpm\.sock|php${PHPV}-fpm.sock|g" "$VHOST"
fi
a2ensite -q freenetis
apache2ctl configtest >/dev/null && systemctl reload apache2

# ── 13. Cron pro schedule:run ────────────────────────────────────────────────
log "Nastavuji cron pro schedule:run..."
CRON_LINE="* * * * * cd $APP_DIR && /usr/bin/php${PHPV} artisan schedule:run >/dev/null 2>&1"
( crontab -u www-data -l 2>/dev/null | grep -v 'schedule:run' ; echo "$CRON_LINE" ) | crontab -u www-data -

# ── 14. Cache config ─────────────────────────────────────────────────────────
log "Cachuji config + routes..."
sudo -u www-data "php${PHPV}" artisan config:cache --no-interaction >/dev/null || true
sudo -u www-data "php${PHPV}" artisan route:cache  --no-interaction >/dev/null || true
sudo -u www-data "php${PHPV}" artisan view:cache   --no-interaction >/dev/null || true

# ── 15. Let's Encrypt přes certbot ───────────────────────────────────────────
echo
log "Spouštím certbot pro $DOMAIN..."
warn "Pro úspěch musí port 80 být dostupný z internetu a A-záznam DNS musí směřovat na tento server."
read -r -p "Spustit certbot teď? [Y/n] " cb
if [[ ! "$cb" =~ ^[nN]$ ]]; then
    certbot --apache --non-interactive --agree-tos --redirect \
        -m "$CERTBOT_EMAIL" -d "$DOMAIN" \
        || warn "certbot selhal — spusť ručně:  certbot --apache -d $DOMAIN"
fi

# ── 16. Hotovo ───────────────────────────────────────────────────────────────
echo
ok "═══════════════════════════════════════════"
ok "  Instalace dokončena"
ok "═══════════════════════════════════════════"
cat <<EOF

URL:           https://$DOMAIN/freenetis
.env:          $APP_DIR/.env  (mode 600, vlastník www-data)

Co ručně doplnit:
  1. PFX certifikát pro digitální podpis smluv:
       cp pvfree.pfx $APP_DIR/storage/app/private/cert/
       chown www-data:www-data $APP_DIR/storage/app/private/cert/pvfree.pfx
       chmod 600 $APP_DIR/storage/app/private/cert/pvfree.pfx
     Pak doplnit v .env:  PDF_SIGN_PASS=<heslo k PFX>

  2. Přílohy pro post-sign emaily (volitelné):
       cp cenik.pdf vop.pdf $APP_DIR/storage/app/private/contracts/

  3. FIO API tokeny per účet:
       admin → Bankovní účty → vyber účet → vyplnit token

  4. SMS_API_KEY pokud zatím prázdný:
       upravit $APP_DIR/.env řádek SMS_API_KEY=<klíč>
       pak: sudo -u www-data php artisan config:cache

Logy:
  $APP_DIR/storage/logs/laravel.log
  /var/log/apache2/freenetis-{access,error}.log

Smoke test:
  curl -sS https://$DOMAIN/freenetis/login -o /dev/null -w "HTTP %{http_code}\\n"
EOF
