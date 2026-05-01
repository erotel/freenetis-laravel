#!/usr/bin/env bash
#
# FreenetIS Laravel — fáze 1: systémové balíčky.
# Idempotentní — lze spustit opakovaně, neudělá nic destruktivního.
#
# Předpoklady:
#   - čerstvá Debian 13 (Trixie) / Ubuntu 24.04 LTS
#   - běží jako root (sudo)
#   - přístup k internetu pro apt
#
# Běh:    sudo bash 01-install-system.sh
# Po něm: 02-configure-app.sh

set -euo pipefail

# ── Logger ───────────────────────────────────────────────────────────────────
log()  { printf '\033[1;34m[*]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[+]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Spusť jako root (sudo bash $0)"

# ── 1. Detekce OS ────────────────────────────────────────────────────────────
. /etc/os-release
case "$ID:$VERSION_ID" in
    debian:13|debian:trixie) PHPV="8.4" ;;
    ubuntu:24.04)            PHPV="8.3" ;;  # Ubuntu 24.04 výchozí PHP je 8.3 (a v archu funguje)
    *) die "Nepodporovaný OS: $PRETTY_NAME (potřebuju Debian 13 nebo Ubuntu 24.04)" ;;
esac
log "Detekováno: $PRETTY_NAME → použiji PHP $PHPV"

# ── 2. apt update + balíčky ──────────────────────────────────────────────────
log "Updatuji apt cache..."
DEBIAN_FRONTEND=noninteractive apt-get update -qq

log "Instaluju balíčky (PHP $PHPV, Apache, MariaDB, certbot, ...)"
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
    apache2 \
    mariadb-server \
    "php${PHPV}-fpm" "php${PHPV}-cli" \
    "php${PHPV}-mysql" "php${PHPV}-mbstring" "php${PHPV}-xml" \
    "php${PHPV}-curl" "php${PHPV}-gd" "php${PHPV}-intl" \
    "php${PHPV}-zip" "php${PHPV}-bcmath" "php${PHPV}-opcache" "php${PHPV}-gmp" \
    composer git unzip ca-certificates curl sudo \
    certbot python3-certbot-apache \
    > /dev/null

ok "Balíčky nainstalovány"

# ── 3. Apache moduly ─────────────────────────────────────────────────────────
log "Aktivuju Apache moduly..."
a2enmod -q proxy proxy_fcgi setenvif rewrite headers ssl http2 || true
a2enconf -q "php${PHPV}-fpm" || true

# Disable default vhost — nahradíme vlastním v 02-
a2dissite -q 000-default 2>/dev/null || true

# ── 4. PHP-FPM opcache ladění ────────────────────────────────────────────────
OPCACHE_FPM="/etc/php/${PHPV}/fpm/conf.d/10-opcache.ini"
log "Nastavuji opcache: $OPCACHE_FPM"
cat > "$OPCACHE_FPM" <<'EOF'
; FreenetIS Laravel tuned opcache (256MB, 20k files, JIT off, validate 2s)
zend_extension=opcache.so
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.jit=off
opcache.fast_shutdown=1
EOF

# ── 5. MariaDB — zajisti že běží + bind na localhost ────────────────────────
systemctl enable -q --now mariadb
systemctl enable -q --now apache2
systemctl enable -q --now "php${PHPV}-fpm"

# ── 6. Restart služeb po conf změnách ────────────────────────────────────────
log "Restartuji php-fpm + apache..."
systemctl restart "php${PHPV}-fpm"
systemctl restart apache2

ok "Systémová fáze hotova."
echo
echo "Verze:"
"php${PHPV}" -v | head -1
apache2 -v | head -1
mariadb --version
echo
echo "Pokračuj druhou fází:"
echo "    sudo bash $(dirname "$(readlink -f "$0")")/02-configure-app.sh"
