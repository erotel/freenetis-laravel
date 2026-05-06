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

# ── Helpers ──────────────────────────────────────────────────────────────────
# env_quote: zabalí hodnotu do .env-bezpečné podoby.
#   Single-quoted ('…') je preferovaná (Dotenv neinterpretuje $/`/\/#/spaces/").
#   Pokud hodnota obsahuje apostrof, fallback na double-quoted s escape sekvencemi.
env_quote() {
    local v="$1"
    if [[ "$v" == *"'"* ]]; then
        v="${v//\\/\\\\}"   # \  →  \\
        v="${v//\$/\\\$}"   # $  →  \$
        v="${v//\"/\\\"}"   # "  →  \"
        printf '"%s"' "$v"
    else
        printf "'%s'" "$v"
    fi
}

# require_ident: zkontroluje, že identifikátor (db user/name) je bezpečný pro SQL.
require_ident() {
    local name="$1" value="$2"
    [[ "$value" =~ ^[a-zA-Z_][a-zA-Z0-9_]*$ ]] \
        || die "$name musí začínat písmenem/_ a obsahovat jen [a-zA-Z0-9_]: '$value'"
}

# require_domain: jednoduchá validace FQDN.
require_domain() {
    [[ "$1" =~ ^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$ ]] \
        || die "Neplatná doména: '$1'"
}

# require_no_apostrophe: heslo nesmí obsahovat apostrof (rozbije SQL CREATE USER).
require_no_apostrophe() {
    local name="$1" value="$2"
    [[ "$value" != *"'"* ]] || die "$name nesmí obsahovat apostrof — zadej znovu bez něj."
}

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
require_domain "$DOMAIN"

CERTBOT_EMAIL="$(ask "E-mail pro Let's Encrypt (notifikace expirace)" "admin@$DOMAIN")"

# DB credentials — minimální dotazování, ostatní se nastaví ve web wizardu.
# Defaultně všechno s vygenerovaným náhodným heslem.
DB_NAME="freenetis"
DB_USER="freenetis"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
CONTRACTS_DB_NAME="contractsdb"
CONTRACTS_DB_USER="contracts"
CONTRACTS_DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"

# Vstupy mail/SMS/dump/admin se přesunuly do web wizardu (/setup).
DUMP_PATH=""
CONTRACTS_DUMP_PATH=""
MAIL_HOST=""
MAIL_PORT="587"
MAIL_USER=""
MAIL_PASS=""
MAIL_FROM="noreply@$DOMAIN"
SMS_API_KEY=""
SMS_SENDER="PVfree"

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
# Spouštíme jako root (jinak www-data nemá perms zapsat do vendor/ při prvním běhu).
# Po composeru chowneme celý adresář na www-data — pak už pro Laravel runtime perms sedí.
log "composer install (může chvilku trvat)..."
cd "$APP_DIR"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --quiet \
    || die "composer install selhal"

log "Nastavuji vlastnictví na www-data..."
chown -R www-data:www-data "$APP_DIR"

# ── 6. Vygeneruj secrets ─────────────────────────────────────────────────────
APP_KEY="$("php${PHPV}" -r 'echo "base64:".base64_encode(random_bytes(32));')"
OTP_PEPPER="$(openssl rand -hex 32)"
CONTRACTS_TOKEN_SECRET="$(openssl rand -hex 32)"

# ── 7. Sestav .env ───────────────────────────────────────────────────────────
# User-supplied secrets (DB_PASS, MAIL_PASS, SMS_API_KEY, ...) projdou přes env_quote,
# aby Laravel Dotenv parser nepadal na $/`/\/#/spaces/quotes v hodnotách.
log "Generuju .env..."
cat > "$APP_DIR/.env" <<EOF
APP_NAME=FreenetIS
APP_ENV=production
APP_KEY=$APP_KEY
APP_DEBUG=false
APP_URL=$(env_quote "https://$DOMAIN/freenetis")
APP_TIMEZONE=Europe/Prague
APP_LOCALE=cs
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$(env_quote "$DB_NAME")
DB_USERNAME=$(env_quote "$DB_USER")
DB_PASSWORD=$(env_quote "$DB_PASS")

CONTRACTS_DB_HOST=127.0.0.1
CONTRACTS_DB_PORT=3306
CONTRACTS_DB_DATABASE=$(env_quote "$CONTRACTS_DB_NAME")
CONTRACTS_DB_USER=$(env_quote "$CONTRACTS_DB_USER")
CONTRACTS_DB_PASS=$(env_quote "$CONTRACTS_DB_PASS")

CACHE_STORE=file
SESSION_DRIVER=file
SESSION_PATH=/freenetis
SESSION_LIFETIME=120
# Cookie posíláme jen přes HTTPS — certbot dotuningne vhost na 443.
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local

MAIL_MAILER=smtp
MAIL_HOST=$(env_quote "$MAIL_HOST")
MAIL_PORT=$MAIL_PORT
MAIL_USERNAME=$(env_quote "$MAIL_USER")
MAIL_PASSWORD=$(env_quote "$MAIL_PASS")
# MAIL_SCHEME nech null — Symfony Mailer aktivuje STARTTLS sám podle portu (587).
MAIL_SCHEME=null
MAIL_FROM_ADDRESS=$(env_quote "$MAIL_FROM")
MAIL_FROM_NAME=FreenetIS

# Smlouvy
CONTRACTS_TOKEN_SECRET=$CONTRACTS_TOKEN_SECRET
CONTRACTS_STORAGE=$(env_quote "$APP_DIR/storage/app/private/contracts/signed")
CONTRACTS_TMP=$(env_quote "$APP_DIR/storage/app/private/contracts/tmp")
CONTRACTS_SMLOUVY_URL=$(env_quote "https://$DOMAIN/freenetis")

# OTP / SMS
OTP_PEPPER=$OTP_PEPPER
OTP_TTL_MIN=5
OTP_MAX_ATTEMPTS=5
OTP_RESEND_WINDOW_SEC=15
OTP_TEST_MODE=false
OTP_TEST_CODE=123456

SMS_API_URL=https://app.smsmanager.cz/api
SMS_API_KEY=$(env_quote "$SMS_API_KEY")
SMS_SENDER=$(env_quote "$SMS_SENDER")

# PDF podpis (vyplň ručně až budeš mít certifikát)
PDF_SIGN_CERT=$(env_quote "$APP_DIR/storage/app/private/cert/pvfree.pfx")
PDF_SIGN_PASS=
PDF_SIGN_NAME=$(env_quote "$SMS_SENDER")
PDF_SIGN_REASON=$(env_quote "Elektronický podpis")
PDF_SIGN_LOCATION=
EOF
chmod 600 "$APP_DIR/.env"
chown www-data:www-data "$APP_DIR/.env"

# ── 8. Vytvoř DB + uživatele ─────────────────────────────────────────────────
# DB_NAME/DB_USER už prošly require_ident regex — bezpečné v SQL identifikátorech.
# Hesla už prošly require_no_apostrophe — bezpečné jako 'string' literál.
# Pro zbylé escape (backslash) zdvojíme \\ na \\\\ pro MySQL string literal.
log "Vytváří DB + uživatele..."
DB_PASS_SQL="${DB_PASS//\\/\\\\}"
CONTRACTS_DB_PASS_SQL="${CONTRACTS_DB_PASS//\\/\\\\}"

mariadb <<SQL || die "MariaDB CREATE DATABASE/USER selhal — ověř, že 'sudo mariadb' funguje bez hesla."
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`$CONTRACTS_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS_SQL';
CREATE USER IF NOT EXISTS '$CONTRACTS_DB_USER'@'localhost' IDENTIFIED BY '$CONTRACTS_DB_PASS_SQL';
ALTER USER '$CONTRACTS_DB_USER'@'localhost' IDENTIFIED BY '$CONTRACTS_DB_PASS_SQL';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$CONTRACTS_DB_NAME\`.* TO '$CONTRACTS_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ── 9. Storage adresáře ──────────────────────────────────────────────────────
log "Vytváří storage adresáře..."
mkdir -p \
    "$APP_DIR/storage/app/private/contracts/"{signed,tmp,cert} \
    "$APP_DIR/storage/framework/"{cache,sessions,views} \
    "$APP_DIR/bootstrap/cache"
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
find "$APP_DIR/storage" -type d -exec chmod 775 {} \;

# ── 10. Setup token ──────────────────────────────────────────────────────────
# Web wizard (/setup) bude gated tímto tokenem — admin si ho přečte z URL,
# kterou skript vypíše na konci. Po dokončení wizardu se token soubor smaže.
log "Generuju setup token pro web wizard..."
SETUP_TOKEN="$(openssl rand -hex 16)"
SETUP_TOKEN_FILE="$APP_DIR/storage/app/setup.token"
echo "$SETUP_TOKEN" > "$SETUP_TOKEN_FILE"
chown www-data:www-data "$SETUP_TOKEN_FILE"
chmod 600 "$SETUP_TOKEN_FILE"

# ── 11. Apache vhost ─────────────────────────────────────────────────────────
log "Aktivuji Apache vhost pro $DOMAIN..."
VHOST="/etc/apache2/sites-available/freenetis.conf"
# DOMAIN už prošla require_domain — nemůže obsahovat / — sed je bezpečné.
sed -e "s/{{DOMAIN}}/$DOMAIN/g" \
    -e "s|{{PHP_FPM_SOCK}}|php${PHPV}-fpm.sock|g" \
    "$TEMPLATE_DIR/apache-freenetis.conf" > "$VHOST"
a2ensite -q freenetis
apache2ctl configtest >/dev/null 2>&1 || die "Apache configtest selhal — zkontroluj $VHOST"
systemctl reload apache2

# ── 12. Cron pro schedule:run ────────────────────────────────────────────────
log "Nastavuji cron pro schedule:run..."
CRON_LINE="* * * * * cd $APP_DIR && /usr/bin/php${PHPV} artisan schedule:run >/dev/null 2>&1"
# Pozn: `crontab -u www-data -l` vrátí exit 1 pokud crontab neexistuje (čerstvá
# instalace), což s set -o pipefail + set -e tiše zabije skript. Proto
# zachycujeme stdout do proměnné a `|| true` chrání před selháním.
EXISTING_CRON="$(crontab -u www-data -l 2>/dev/null | grep -v 'schedule:run' || true)"
printf '%s\n%s\n' "$EXISTING_CRON" "$CRON_LINE" | crontab -u www-data -

# ── 13. Config cache (po web wizardu si to wizard znovu zopakuje včetně route/view) ────
log "Cachuji config..."
runuser -u www-data -- "php${PHPV}" artisan config:cache --no-interaction >/dev/null || true

# ── 14. Let's Encrypt přes certbot ───────────────────────────────────────────
echo
if [[ -d "/etc/letsencrypt/live/$DOMAIN" ]] \
        && [[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]]; then
    log "Cert pro $DOMAIN už existuje (/etc/letsencrypt/live/$DOMAIN), spouštím jen 'certbot install' pro Apache..."
    certbot install --cert-name "$DOMAIN" --apache --non-interactive \
        || warn "certbot install selhal — Apache vhost nakonfiguruj ručně"
else
    log "Spouštím certbot pro $DOMAIN..."
    warn "Pro úspěch musí port 80 být dostupný z internetu a A-záznam DNS musí směřovat na tento server."
    warn "Let's Encrypt má rate limit 5 cert/168h na stejnou doménu — pokud ho vyčerpáš, použij --staging nebo subdoménu."
    read -r -p "Spustit certbot teď? [Y/n] " cb
    if [[ ! "$cb" =~ ^[nN]$ ]]; then
        certbot --apache --non-interactive --agree-tos --redirect \
            -m "$CERTBOT_EMAIL" -d "$DOMAIN" \
            || warn "certbot selhal — možná rate limit. Zkus později nebo:  certbot --apache --staging -d $DOMAIN"
    fi
fi

# ── 16. Smoke test ───────────────────────────────────────────────────────────
echo
log "Smoke testy..."

apache2ctl configtest >/dev/null 2>&1 \
    && ok "Apache configtest: Syntax OK" \
    || warn "Apache configtest selhal"

[ -S "/run/php/php${PHPV}-fpm.sock" ] \
    && ok "PHP-FPM socket dostupný" \
    || warn "PHP-FPM socket /run/php/php${PHPV}-fpm.sock chybí"

if curl -skfI "https://$DOMAIN/" --max-time 5 >/dev/null 2>&1; then
    LOC="$(curl -skI "https://$DOMAIN/" --max-time 5 | tr -d '\r' | grep -i '^location:' | head -1)"
    if echo "$LOC" | grep -qi '/freenetis'; then
        ok "Root redirect: $LOC"
    else
        warn "Root redirect NEFUNGUJE správně: ${LOC:-(žádný Location header)}"
    fi
    CODE="$(curl -sk "https://$DOMAIN/freenetis/login" --max-time 5 -o /dev/null -w '%{http_code}')"
    case "$CODE" in
        200|302) ok "/freenetis/login: HTTP $CODE" ;;
        *)       warn "/freenetis/login: HTTP $CODE (očekáváno 200 nebo 302)" ;;
    esac
else
    warn "HTTPS nedostupné — smoke test přeskočen (možná certbot neproběhl, nebo ještě DNS nepropagované)"
fi

crontab -u www-data -l 2>/dev/null | grep -q 'schedule:run' \
    && ok "Cron pro schedule:run zaregistrován" \
    || warn "Cron NENÍ zaregistrován"

runuser -u www-data -- "php${PHPV}" "$APP_DIR/artisan" db:show --database=mysql 2>&1 | grep -q 'Connected\|Database' \
    && ok "DB connection: OK" \
    || warn "DB connection: nedostupné (zkontroluj .env DB_*)"

# ── 17. Hotovo ───────────────────────────────────────────────────────────────
echo
ok "═══════════════════════════════════════════"
ok "  Systémová instalace dokončena"
ok "═══════════════════════════════════════════"
cat <<EOF

DALŠÍ KROK — otevři v prohlížeči setup wizard:

   ┌─────────────────────────────────────────────────────────────────────┐
   │                                                                     │
   │  https://$DOMAIN/freenetis/setup?t=$SETUP_TOKEN
   │                                                                     │
   └─────────────────────────────────────────────────────────────────────┘

Wizard tě provede:
  - Volba: čistá instalace (vytvoří organizaci + admin účet)
           NEBO migrace ze starého serveru (upload SQL dumpu, max 2 GB)
  - Import schématu, migrace, ACL seedery
  - Vytvoření admin účtu (jen u čisté instalace)

Po dokončení wizardu se setup token automaticky smaže a stránka /setup
přestane být dostupná.

──────────────────────────────────────────────
Co později ručně doplnit (přes admin UI nebo .env):
  - PFX cert pro podpis smluv:
      cp pvfree.pfx $APP_DIR/storage/app/private/cert/
      chown www-data:www-data $APP_DIR/storage/app/private/cert/pvfree.pfx && chmod 600 $APP_DIR/storage/app/private/cert/pvfree.pfx
      doplň v .env: PDF_SIGN_PASS=<heslo>
  - Příchozí maily přes admin → Nastavení (SMTP host/user/pass)
  - SMS přes admin → Nastavení (SMS_API_KEY)
  - FIO API tokeny per účet — admin → Bankovní účty → token
  - phpMyAdmin (volitelné):
      bash $(dirname "$(readlink -f "$0")")/03-install-phpmyadmin.sh

Logy:
  $APP_DIR/storage/logs/laravel.log
  /var/log/apache2/freenetis-{access,error}.log

Setup token (kdyby URL výše ztratila):
  cat $SETUP_TOKEN_FILE
EOF
