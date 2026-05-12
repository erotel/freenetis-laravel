#!/usr/bin/env bash
#
# FreenetIS Laravel — bootstrap installer.
# One-shot pipe-able skript, který stáhne repo přímo do produkčního umístění
# a spustí instalační fáze. Vyhne se klonování repa dvakrát.
#
# Použití:
#   curl -fsSL https://raw.githubusercontent.com/erotel/freenetis-laravel/laravel-migration/scripts/install/bootstrap.sh | sudo bash
#
# Volitelně:
#   REPO_BRANCH=jina-vetev  curl ... | sudo bash
#   APP_DIR=/srv/freenetis  curl ... | sudo bash
#   SKIP_PHASE_2=1          curl ... | sudo bash    # jen systémové balíčky, 02 spustíš ručně

set -euo pipefail

REPO_URL="${REPO_URL:-https://github.com/erotel/freenetis-laravel.git}"
REPO_BRANCH="${REPO_BRANCH:-laravel-migration}"
APP_DIR="${APP_DIR:-/var/www/html/freenetis-laravel}"

log()  { printf '\033[1;34m[*]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m[+]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[!]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x]\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Spusť jako root (curl ... | sudo bash)"

# 1. Zajisti git (bez něj nemůžeme klonovat)
if ! command -v git >/dev/null 2>&1; then
    log "Instaluju git + ca-certificates (jen co minimum k naklonování)..."
    DEBIAN_FRONTEND=noninteractive apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git ca-certificates
fi

# 2. Naklonuj (nebo updatuj) repo přímo do produkčního umístění
if [[ -d "$APP_DIR/.git" ]]; then
    log "Repo už existuje v $APP_DIR — pull..."
    git -C "$APP_DIR" fetch --quiet origin "$REPO_BRANCH"
    git -C "$APP_DIR" checkout --quiet "$REPO_BRANCH"
    git -C "$APP_DIR" pull --quiet --ff-only origin "$REPO_BRANCH"
else
    log "Klonuji $REPO_URL ($REPO_BRANCH) → $APP_DIR"
    mkdir -p "$(dirname "$APP_DIR")"
    git clone --quiet --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi
ok "Repo připravené: $APP_DIR"

# 3. Fáze 1 — systémové balíčky (neinteraktivní)
log "Spouštím 01-install-system.sh..."
bash "$APP_DIR/scripts/install/01-install-system.sh"

# 4. Fáze 2 — konfigurace aplikace (interaktivní, ptá se na doménu/DB/...)
if [[ "${SKIP_PHASE_2:-0}" == "1" ]]; then
    warn "SKIP_PHASE_2=1 — fázi 2 přeskakuji."
    echo
    echo "Až budeš chtít, spusť:  sudo bash $APP_DIR/scripts/install/02-configure-app.sh"
    exit 0
fi

# 02 čte z stdin (read -p). Při curl|bash je stdin zabraný pipou, takže
# explicitně přesměrujeme z /dev/tty (pokud existuje — jinak musí user spustit ručně).
if [[ -e /dev/tty ]]; then
    log "Spouštím 02-configure-app.sh (vstup z /dev/tty)..."
    bash "$APP_DIR/scripts/install/02-configure-app.sh" < /dev/tty
else
    warn "/dev/tty není dostupné, 02 je interaktivní — spusť ručně:"
    echo "  sudo bash $APP_DIR/scripts/install/02-configure-app.sh"
    exit 0
fi
