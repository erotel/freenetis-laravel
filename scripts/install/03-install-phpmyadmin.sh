#!/usr/bin/env bash
#
# FreenetIS Laravel — fáze 3 (volitelné): phpMyAdmin.
# Standalone skript — můžeš spustit kdykoliv po fázi 1+2.
#
# Bezpečnostní defaulty:
#   - Custom URL prefix (ne výchozí /phpmyadmin který skenují botnety)
#   - HTTP Basic Auth nad PMA loginem (2 faktory)
#   - Volitelný IP allow-list (Apache `Require ip ...`)
#   - Bez phpmyadmin metadata DB (login se dělá jako existující DB uživatel —
#     např. freenetis, který už máš z fáze 2)
#
# Běh:    sudo bash 03-install-phpmyadmin.sh
# Re-run: bezpečné — přepíše Apache conf a htpasswd. PMA balíček se znovu neinstaluje.

set -euo pipefail

log()  { printf '\033[1;34m[*]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[+]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Spusť jako root (sudo bash $0)"

# ── 1. Vstupy ────────────────────────────────────────────────────────────────
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

echo
echo "═══════════════════════════════════════════"
echo "  phpMyAdmin — instalace + zabezpečení"
echo "═══════════════════════════════════════════"
echo
echo "Doporučení:"
echo "  - URL path: nech vygenerovat náhodný (botnety skenují /phpmyadmin)"
echo "  - HTTP Basic Auth: NEpoužívej stejné heslo jako MySQL"
echo "  - IP allow-list: pokud máš pevnou IP / VPN, přidej ji"
echo

PMA_PATH="$(ask "URL cesta" "/dbadmin-$(openssl rand -hex 4)")"
[[ "$PMA_PATH" =~ ^/[a-zA-Z0-9_/-]+$ ]] || die "URL cesta musí začínat / a obsahovat jen [a-zA-Z0-9_/-]"

PMA_USER="$(ask "HTTP Basic Auth uživatel" "pma")"
[[ "$PMA_USER" =~ ^[a-zA-Z0-9_-]+$ ]] || die "Uživatel obsahuje nepovolené znaky"

PMA_PASS="$(ask_secret "HTTP Basic Auth heslo (Enter = vygeneruj)")"
[[ -z "$PMA_PASS" ]] && PMA_PASS="$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)"

echo
echo "IP allow-list — omezí, kdo se může vůbec dostat k loginu."
echo "Příklady: 192.168.1.0/24    nebo:    1.2.3.4 5.6.7.8 2001:db8::/32"
echo "Prázdné = kdokoliv s HTTP Basic + DB credentials."
PMA_IP_ALLOW="$(ask "IP/CIDR allow-list" "")"

echo
echo "── Souhrn ──────────────────────────────────"
echo "  URL path:           $PMA_PATH"
echo "  HTTP Basic user:    $PMA_USER"
echo "  HTTP Basic pass:    [skryto]"
echo "  IP allow-list:      ${PMA_IP_ALLOW:-(žádný — kdokoliv s heslem)}"
echo
read -r -p "Pokračovat? [y/N] " confirm
[[ "$confirm" =~ ^[yY]$ ]] || die "Zrušeno uživatelem"

# ── 2. Preseed phpmyadmin debconf ────────────────────────────────────────────
# Bez dbconfig-common: nevytváří se phpmyadmin metadata DB ani phpmyadmin user.
# Login bude přes existující DB credentials (freenetis user z fáze 2).
log "Preseeding phpmyadmin debconf..."
debconf-set-selections <<'EOF_PMA'
phpmyadmin phpmyadmin/dbconfig-install boolean false
phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2
phpmyadmin phpmyadmin/internal/skip-preseed boolean true
EOF_PMA

# ── 3. apt install phpmyadmin + apache2-utils (kvůli htpasswd) ───────────────
log "Instaluju phpmyadmin (může chvilku trvat — ~100 MB)..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    phpmyadmin apache2-utils > /dev/null

# ── 4. Disable výchozího /phpmyadmin Aliasu (bezpečnost) ─────────────────────
log "Disablingu výchozí Apache conf (Alias /phpmyadmin)..."
a2disconf -q phpmyadmin 2>/dev/null || true

# ── 5. htpasswd file ─────────────────────────────────────────────────────────
HTPASSWD_FILE="/etc/apache2/.htpasswd-pma"
log "Vytváří htpasswd file: $HTPASSWD_FILE"
htpasswd -bcB "$HTPASSWD_FILE" "$PMA_USER" "$PMA_PASS"
chown root:www-data "$HTPASSWD_FILE"
chmod 640 "$HTPASSWD_FILE"

# ── 6. Apache conf — Alias + Auth + IP guard ─────────────────────────────────
PMA_CONF="/etc/apache2/conf-available/freenetis-pma.conf"
log "Vytváří Apache conf: $PMA_CONF"

{
    cat <<EOF_CONF
# phpMyAdmin — vlastní URL prefix + HTTP Basic Auth + IP allow-list
# Generováno 03-install-phpmyadmin.sh — pro úpravy edituj heredoc tam.

Alias $PMA_PATH /usr/share/phpmyadmin

<Directory /usr/share/phpmyadmin>
    Options SymLinksIfOwnerMatch
    DirectoryIndex index.php
    AllowOverride None

    <RequireAll>
        Require valid-user
EOF_CONF

    if [[ -n "$PMA_IP_ALLOW" ]]; then
        echo "        Require ip $PMA_IP_ALLOW"
    fi

    cat <<EOF_CONF
    </RequireAll>

    AuthType Basic
    AuthName "FreenetIS DB Admin"
    AuthUserFile $HTPASSWD_FILE

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
</Directory>

# Setup / install scripty phpMyAdmin nikdy nesmí být dostupné z webu.
<Directory /usr/share/phpmyadmin/setup>
    Require all denied
</Directory>
<Directory /usr/share/phpmyadmin/libraries>
    Require all denied
</Directory>
<Directory /usr/share/phpmyadmin/templates>
    Require all denied
</Directory>
EOF_CONF
} > "$PMA_CONF"

# Patch php-fpm socket podle reálné verze (Debian 13 = 8.4, Ubuntu 24.04 = 8.3)
if command -v php8.3 &>/dev/null && ! command -v php8.4 &>/dev/null; then
    sed -i 's|php8\.4-fpm\.sock|php8.3-fpm.sock|g' "$PMA_CONF"
fi

a2enconf -q freenetis-pma
apache2ctl configtest >/dev/null 2>&1 || die "Apache configtest selhal — zkontroluj $PMA_CONF"
systemctl reload apache2

# ── 7. Smoke test ────────────────────────────────────────────────────────────
log "Smoke test..."

# Detekuj domain z .env (APP_URL)
APP_URL_ENV="$(grep -E '^APP_URL=' /var/www/html/freenetis-laravel/.env 2>/dev/null | cut -d= -f2- | tr -d "'\"")"
DOMAIN_FROM_ENV="$(echo "$APP_URL_ENV" | sed -E 's|^https?://([^/]+).*|\1|')"

if [[ -n "$DOMAIN_FROM_ENV" ]]; then
    # Bez Basic Auth — musíme dostat 401
    HTTP_CODE="$(curl -sk "https://$DOMAIN_FROM_ENV$PMA_PATH/" --max-time 5 -o /dev/null -w '%{http_code}' || echo "000")"
    case "$HTTP_CODE" in
        401)     ok "Basic Auth aktivní (HTTP 401 bez creds — správně)" ;;
        403)     ok "IP allow-list blokuje (HTTP 403 — správně, pokud nejsi v allow-listu)" ;;
        200)     warn "HTTP 200 bez Basic Auth — Auth nefunguje! Zkontroluj $PMA_CONF" ;;
        000)     warn "HTTPS nedostupné nebo timeout — ověř, že /etc/apache2/sites-enabled má SSL vhost (certbot)" ;;
        *)       warn "Neočekávaný HTTP $HTTP_CODE" ;;
    esac

    # S Basic Auth — musíme dostat 200 (login form phpMyAdmin)
    HTTP_CODE_AUTH="$(curl -sk "https://$DOMAIN_FROM_ENV$PMA_PATH/" --max-time 5 \
        -u "$PMA_USER:$PMA_PASS" -o /dev/null -w '%{http_code}' || echo "000")"
    case "$HTTP_CODE_AUTH" in
        200)     ok "Basic Auth s creds projde (HTTP 200)" ;;
        403)     ok "IP allow-list blokuje (HTTP 403 — správně, pokud nejsi v allow-listu)" ;;
        *)       warn "S Basic Auth creds vrací HTTP $HTTP_CODE_AUTH (očekáváno 200)" ;;
    esac
fi

# ── 8. Hotovo ────────────────────────────────────────────────────────────────
echo
ok "═══════════════════════════════════════════"
ok "  phpMyAdmin nainstalován"
ok "═══════════════════════════════════════════"
cat <<EOF

URL:                https://${DOMAIN_FROM_ENV:-<DOMÉNA>}$PMA_PATH/
HTTP Basic user:    $PMA_USER
HTTP Basic pass:    $PMA_PASS    ←  ULOŽ SI BEZPEČNĚ! Heslo se znovu nezobrazí.
IP allow-list:      ${PMA_IP_ALLOW:-(žádný)}

Login do phpMyAdmin (po projití HTTP Basic):
  - Server:   localhost
  - User:     freenetis     (nebo jiný DB user)
  - Heslo:    najdeš v /var/www/html/freenetis-laravel/.env (DB_PASSWORD=...)

Soubory ke konfiguraci:
  $PMA_CONF              ← Apache vhost konfigurace
  $HTPASSWD_FILE         ← htpasswd (mode 640, root:www-data)

Zrušení phpMyAdmin (kdykoliv):
  sudo a2disconf freenetis-pma
  sudo systemctl reload apache2
  sudo apt purge phpmyadmin    # volitelné — odstraní i package
EOF
