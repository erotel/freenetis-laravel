#!/usr/bin/env bash
#
# FreenetIS Laravel — deploy / update existing instance.
#
# Použití (z kořene repa, typicky /var/www/html/freenetis-laravel):
#   sudo ./scripts/deploy.sh                   # standardní update
#   sudo ./scripts/deploy.sh --reload-apache   # navíc reload Apache (potřeba při opcache.validate_timestamps=0)
#   sudo ./scripts/deploy.sh --skip-migrate    # přeskočit migrace (např. když ručně řešíš schéma)
#
# Co dělá v pořadí:
#   1. git pull (na aktuální větvi)
#   2. composer install --no-dev --optimize-autoloader
#   3. php artisan migrate --force          (lze přeskočit)
#   4. php artisan optimize:clear           (config/view/route/event cache)
#   5. opravu vlastnictví storage/ + bootstrap/cache/
#   6. reload Apache, pokud bylo požádáno
#
# První instalace probíhá přes scripts/install/02-configure-app.sh, ne tudy.

set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"

RELOAD_APACHE=0
SKIP_MIGRATE=0
for arg in "$@"; do
    case "$arg" in
        --reload-apache) RELOAD_APACHE=1 ;;
        --skip-migrate)  SKIP_MIGRATE=1 ;;
        -h|--help)
            sed -n '3,15p' "$0"
            exit 0
            ;;
        *)
            echo "Neznámý argument: $arg" >&2
            exit 1
            ;;
    esac
done

step() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m✓ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m! %s\033[0m\n' "$*"; }

# Web user pro chown — composer/artisan musí běžet pod ním, jinak storage/ vlastní root.
WEB_USER="www-data"
if ! id -u "$WEB_USER" >/dev/null 2>&1; then
    warn "Uživatel $WEB_USER neexistuje — chown přeskočím, ujisti se, že storage/ je psatelné."
    WEB_USER=""
fi

# Pokud běžíme jako root, přepneme composer/artisan na web usera (aby vendor/ neměl root vlastnictví).
RUN_AS_WEB=()
if [ "$(id -u)" -eq 0 ] && [ -n "$WEB_USER" ]; then
    RUN_AS_WEB=(sudo -u "$WEB_USER" -H)
fi

step "1/5 git pull"
if ! git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Tento adresář není git repo: $REPO_ROOT" >&2
    exit 1
fi
BRANCH="$(git -C "$REPO_ROOT" rev-parse --abbrev-ref HEAD)"
git -C "$REPO_ROOT" pull --ff-only origin "$BRANCH"
ok "Větev $BRANCH aktuální."

step "2/5 composer install"
"${RUN_AS_WEB[@]}" composer install --no-dev --optimize-autoloader --no-interaction
ok "Závislosti instalovány."

if [ "$SKIP_MIGRATE" -eq 1 ]; then
    warn "3/5 migrate přeskočeno (--skip-migrate)"
else
    step "3a/5 artisan migrate:reconcile (sync s legacy schématem)"
    # Označí pending migrace, jejichž tabulky/sloupce už existují (z legacy dumpu
    # nebo paralelního provisioningu) jako already-applied — bez toho by migrate
    # padal na 1050 "Table already exists".
    "${RUN_AS_WEB[@]}" php artisan migrate:reconcile

    step "3b/5 artisan migrate --force"
    "${RUN_AS_WEB[@]}" php artisan migrate --force
    ok "Migrace hotové."
fi

step "4/5 artisan optimize:clear"
"${RUN_AS_WEB[@]}" php artisan optimize:clear
ok "Cache vyčištěna."

step "5/5 chown storage/ + bootstrap/cache/"
if [ -n "$WEB_USER" ] && [ "$(id -u)" -eq 0 ]; then
    chown -R "$WEB_USER:$WEB_USER" "$REPO_ROOT/storage" "$REPO_ROOT/bootstrap/cache"
    ok "Vlastnictví nastaveno na $WEB_USER."
else
    warn "Neběžím jako root nebo $WEB_USER neexistuje — chown přeskočen."
fi

if [ "$RELOAD_APACHE" -eq 1 ]; then
    step "Bonus: systemctl reload apache2"
    systemctl reload apache2
    ok "Apache reloadnut."
fi

printf '\n\033[1;32m✓ Deploy hotov.\033[0m\n'
