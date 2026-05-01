#!/usr/bin/env bash
#
# FreenetIS Laravel — fáze 3 (volitelné): phpMyAdmin.
# Standalone skript — můžeš spustit kdykoliv po fázi 1+2.
#
# Bezpečnostní defaulty:
#   - Custom URL prefix (ne výchozí /phpmyadmin který skenují botnety)
#   - Tvrdý IP allow-list — přístup pouze z vnitřní sítě (mandatorní, není
#     možné nechat prázdný). MySQL credentials zůstávají jediné login factor.
#   - Bez phpmyadmin metadata DB (login se dělá jako existující DB uživatel —
#     např. freenetis, který už máš z fáze 2)
#
# Běh:    sudo bash 03-install-phpmyadmin.sh
# Re-run: bezpečné — přepíše Apache conf. PMA balíček se znovu neinstaluje.

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

echo
echo "═══════════════════════════════════════════"
echo "  phpMyAdmin — instalace + IP allow-list"
echo "═══════════════════════════════════════════"
echo
echo "Bezpečnost:"
echo "  - Žádný HTTP Basic Auth — login je čistě přes MySQL credentials."
echo "  - Přístup je omezen na IP allow-list (mandatorní, nelze nechat prázdné)."
echo "  - Custom URL path místo /phpmyadmin (botnety to skenují)."
echo

PMA_PATH="$(ask "URL cesta" "/dbadmin-$(openssl rand -hex 4)")"
[[ "$PMA_PATH" =~ ^/[a-zA-Z0-9_/-]+$ ]] || die "URL cesta musí začínat / a obsahovat jen [a-zA-Z0-9_/-]"

echo
echo "IP allow-list — KDO se vůbec dostane k loginu phpMyAdmin."
echo "Zadej jednu nebo více IP/CIDR oddělených mezerou."
echo "Příklady:"
echo "  10.133.0.0/16              jedna privátní LAN"
echo "  192.168.1.0/24 1.2.3.4     LAN + jednotlivá public IP"
echo "  10.0.0.0/8 192.168.0.0/16  všechny privátní RFC1918 (dle vkusu)"
echo
PMA_IP_ALLOW="$(ask "IP/CIDR allow-list (povinné)" "")"
[[ -n "$PMA_IP_ALLOW" ]] || die "IP allow-list je povinný — bez něj by phpMyAdmin byl dostupný komukoliv s URL."

# Hard guard: přijmi jen "rozumné" tokeny (cifry, tečky, lomítka, dvojtečky pro
# IPv6, mezery, písmena pro IPv6 hex). Žádné středníky / dolary atd.
[[ "$PMA_IP_ALLOW" =~ ^[a-fA-F0-9./:[:space:]]+$ ]] \
    || die "IP allow-list obsahuje nepovolené znaky: '$PMA_IP_ALLOW'"

echo
echo "── Souhrn ──────────────────────────────────"
echo "  URL path:           $PMA_PATH"
echo "  IP allow-list:      $PMA_IP_ALLOW"
echo "  Login factor:       MySQL credentials (jen z DB, žádný HTTP Basic)"
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

# ── 3. apt install phpmyadmin ────────────────────────────────────────────────
log "Instaluju phpmyadmin (může chvilku trvat — ~100 MB)..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq phpmyadmin > /dev/null

# ── 4. Disable výchozího /phpmyadmin Aliasu (bezpečnost) ─────────────────────
log "Disablingu výchozí Apache conf (Alias /phpmyadmin)..."
a2disconf -q phpmyadmin 2>/dev/null || true

# ── 5. Apache conf — Alias + IP guard ────────────────────────────────────────
PMA_CONF="/etc/apache2/conf-available/freenetis-pma.conf"
log "Vytváří Apache conf: $PMA_CONF"

cat > "$PMA_CONF" <<EOF_CONF
# phpMyAdmin — vlastní URL prefix + tvrdý IP allow-list.
# Generováno 03-install-phpmyadmin.sh — pro úpravy edituj tam, nebo přepiš ručně.

Alias $PMA_PATH /usr/share/phpmyadmin

<Directory /usr/share/phpmyadmin>
    Options SymLinksIfOwnerMatch
    DirectoryIndex index.php
    AllowOverride None

    # Přístup pouze z povolených IP. MySQL credentials = jediný login factor.
    Require ip $PMA_IP_ALLOW

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost"
    </FilesMatch>
</Directory>

# Setup / install / library skripty phpMyAdmin nikdy nesmí být dostupné z webu.
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

# Patch php-fpm socket podle reálné verze (Debian 13 = 8.4, Ubuntu 24.04 = 8.3)
if command -v php8.3 &>/dev/null && ! command -v php8.4 &>/dev/null; then
    sed -i 's|php8\.4-fpm\.sock|php8.3-fpm.sock|g' "$PMA_CONF"
fi

a2enconf -q freenetis-pma
apache2ctl configtest >/dev/null 2>&1 || die "Apache configtest selhal — zkontroluj $PMA_CONF"
systemctl reload apache2

# ── 6. Smoke test ────────────────────────────────────────────────────────────
log "Smoke test..."

# Detekuj domain z .env (APP_URL)
APP_URL_ENV="$(grep -E '^APP_URL=' /var/www/html/freenetis-laravel/.env 2>/dev/null | cut -d= -f2- | tr -d "'\"")"
DOMAIN_FROM_ENV="$(echo "$APP_URL_ENV" | sed -E 's|^https?://([^/]+).*|\1|')"

if [[ -n "$DOMAIN_FROM_ENV" ]]; then
    # Z localhost / 127.0.0.1 — záleží jestli je localhost v allow-listu.
    # Většinou ne, takže očekáváme 403.
    HTTP_CODE="$(curl -sk "https://$DOMAIN_FROM_ENV$PMA_PATH/" --max-time 5 -o /dev/null -w '%{http_code}' || echo "000")"
    case "$HTTP_CODE" in
        200)
            ok "Localhost je v allow-listu, phpMyAdmin login form se renderuje (HTTP 200)" ;;
        403)
            ok "Localhost NENÍ v allow-listu (HTTP 403 — správně, ověřuje to že IP guard funguje)" ;;
        000)
            warn "HTTPS nedostupné nebo timeout — ověř, že /etc/apache2/sites-enabled má SSL vhost (certbot)" ;;
        *)
            warn "Neočekávaný HTTP $HTTP_CODE" ;;
    esac
fi

# ── 7. Hotovo ────────────────────────────────────────────────────────────────
echo
ok "═══════════════════════════════════════════"
ok "  phpMyAdmin nainstalován"
ok "═══════════════════════════════════════════"
cat <<EOF

URL:                https://${DOMAIN_FROM_ENV:-<DOMÉNA>}$PMA_PATH/
IP allow-list:      $PMA_IP_ALLOW

Login do phpMyAdmin (přihlašovací stránka se otevře jen z povolené IP):
  - Server:   localhost
  - User:     freenetis     (nebo jiný DB user — root, contracts, …)
  - Heslo:    najdeš v /var/www/html/freenetis-laravel/.env  (DB_PASSWORD=...)

Soubor ke konfiguraci:
  $PMA_CONF

Změnit IP allow-list (kdykoliv):
  sudo $0     ← spusť skript znovu, vyplň jiný allow-list
  NEBO ručně edituj $PMA_CONF (řádek "Require ip ...") a:
       sudo systemctl reload apache2

Zrušení phpMyAdmin:
  sudo a2disconf freenetis-pma
  sudo systemctl reload apache2
  sudo apt purge phpmyadmin    # volitelné — odstraní i package
EOF
