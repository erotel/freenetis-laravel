# FreenetIS Laravel

Přepis FreenetIS (původně Kohana 2 PHP framework) do **Laravel 13**.
Informační systém pro provoz síťařských spolků: členská evidence, finance,
DHCP, captive portal, smlouvy s elektronickým podpisem, GPON, SMS, …

Aktuální verze: **v2.0.0** (viz `config/version.php`). v1.x byla legacy Kohana.

> Status: One-man show s asistencí Claude AI. Aktivní vývoj na větvi `laravel-migration`.

---

## Rychlá instalace na čistý server

**Předpoklady:** čerstvý **Debian 13 (Trixie)** nebo **Ubuntu 24.04 LTS**, root přístup,
A-záznam DNS směřující na server (např. `is.spolek.net`).

### Varianta A — one-liner (doporučeno)

```bash
curl -fsSL https://raw.githubusercontent.com/erotel/freenetis-laravel/laravel-migration/scripts/install/bootstrap.sh | sudo bash
```

Bootstrap zařídí git, naklonuje repo přímo do `/var/www/html/freenetis-laravel`,
spustí 01 (systémové balíčky) a 02 (interaktivní konfigurace s otázkami na doménu,
e-mail pro Let's Encrypt, …). 02 čte vstup z `/dev/tty`, takže `curl | bash` funguje.

ENV overrides:
```bash
REPO_BRANCH=jina-vetev APP_DIR=/srv/freenetis SKIP_PHASE_2=1 \
    curl -fsSL https://raw.githubusercontent.com/erotel/freenetis-laravel/laravel-migration/scripts/install/bootstrap.sh | sudo bash
```

### Varianta B — manuální (žádný stažený third-party skript)

```bash
sudo apt-get update && sudo apt-get install -y git
sudo git clone -b laravel-migration https://github.com/erotel/freenetis-laravel.git /var/www/html/freenetis-laravel
cd /var/www/html/freenetis-laravel
sudo bash scripts/install/01-install-system.sh
sudo bash scripts/install/02-configure-app.sh
# → Zeptá se na: doménu (FQDN), e-mail pro Let's Encrypt
# → Na konci vypíše URL pro web setup wizard
```

02 detekuje existující `.git` v cílovém umístění a místo druhého klonu udělá jen `git pull`.

Skript `02-configure-app.sh` na konci vypíše URL ve tvaru:

```
https://is.spolek.net/freenetis/setup?t=<32-hex-token>
```

**Otevři tu URL v prohlížeči** (anonymní okno doporučeno) a projeď wizard:

| Volba                            | Co dělá                                                          |
|----------------------------------|------------------------------------------------------------------|
| **Čistá instalace**              | Naimportuje prázdné schéma + ACL. Vyplníš jméno organizace + admin login/heslo. |
| **Migrace ze starého serveru**   | Nahraješ SQL dump (max 2 GB, `.sql` nebo `.sql.gz`). Admin účty jsou v dumpu. |

Po dokončení wizardu se setup token automaticky smaže a `/setup` přestane být dostupný. Wizard tě přesměruje na `/login`.

---

## Update existující instance

```bash
cd /var/www/html/freenetis-laravel
sudo ./scripts/deploy.sh
```

Co dělá v pořadí:

1. `git pull --ff-only` na aktuální větvi
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate:reconcile` — registruje migrace, jejichž tabulky už existují
   (legacy importy, paralelní provisioning), takže další krok neselže na 1050
4. `php artisan migrate --force`
5. `php artisan optimize:clear` — bez tohohle se nově přidané `config/*.php`
   soubory nepropíší do aplikace (viz historický bug s `config/version.php`)
6. Chown `storage/` a `bootstrap/cache/` na `www-data`

Composer/artisan kroky se spouští pod `www-data` (pokud běžíš jako root), takže
`vendor/` ani Laravel cache nedostanou root ownership.

Volby:
- `--reload-apache` — pokud máš `opcache.validate_timestamps=0`
- `--skip-migrate` — když ručně řešíš schéma

---

## Volitelné: phpMyAdmin

Pokud chceš spravovat DB přes web rozhraní:

```bash
bash scripts/install/03-install-phpmyadmin.sh
# → Zeptá se na: URL prefix (default /dbadmin-<8 hex>), IP allow-list (povinný)
```

phpMyAdmin je gated **tvrdým IP allow-listem** — login je čistě přes MySQL credentials,
žádný HTTP Basic. Defaultní `/phpmyadmin` URL je vypnutý (botnety ho skenují).

DB heslo pro login (uživatel `freenetis`): viz `/var/www/html/freenetis-laravel/.env` řádek `DB_PASSWORD`.

---

## Co skript automatizuje

**`01-install-system.sh`** (idempotentní, bezpečně re-runnable):
- `apt install`: apache2, mariadb-server, php-fpm + extensions, composer, certbot, sudo
- Apache moduly: `proxy_fcgi`, `rewrite`, `headers`, `ssl`, `http2`
- Opcache tuning (256 MB, 20k files, validate 2s, JIT off)
- PHP upload limity 2 GB (kvůli velkým SQL dumpům v setup wizardu)

**`02-configure-app.sh`** (interaktivní, jednorázový):
- Naclonuje repo do `/var/www/html/freenetis-laravel`
- `composer install --no-dev --optimize-autoloader`
- Generuje `.env` s náhodnými secrets:
  - `APP_KEY` (Laravel cipher key)
  - `OTP_PEPPER` (HMAC pro OTP hashování)
  - `CONTRACTS_TOKEN_SECRET` (HMAC pro sign-link tokeny)
- Vytvoří 2 MariaDB databáze (`freenetis` + `contractsdb`) + uživatele s náhodnými hesly
- Apache vhost s `Alias /freenetis` a redirectem z root
- Cron `* * * * * php artisan schedule:run`
- Setup token (`storage/app/setup.token`) pro web wizard
- Volitelně: certbot pro Let's Encrypt cert (s detekcí existujícího cert pro re-runy)

**Web setup wizard** (`/setup?t=<token>`):
- Volba **Čistá instalace** → import bootstrap schema (118 tabulek + ACL/enum_types/messages) + form pro admin
- Volba **Migrace** → upload SQL dump (stream přes `gunzip | mariadb`, žádná velikostní limita kromě 2 GB upload)
- `php artisan migrate` (přidá inkrementální Laravel migrace)
- `php artisan db:seed` (ACL pro Gpon + SMTP exceptions + Contracts)
- `email_queues idx_state` index (perf)
- `php artisan freenetis:install` (vytvoří organizaci + admin + zařadí do System administrators)
- `config:cache` + `view:cache`

---

## Po instalaci — co ručně doplnit

Některé věci nejdou automatizovat, doplníš v admin UI nebo ručně:

| Co                      | Jak                                                                                          |
|-------------------------|----------------------------------------------------------------------------------------------|
| **PFX cert pro digitální podpis smluv** | Zkopíruj `.pfx` do `/var/www/html/freenetis-laravel/storage/app/private/cert/pvfree.pfx` (mode 0600, www-data). Heslo doplň v `.env` řádek `PDF_SIGN_PASS=…`, pak `php artisan config:cache`. |
| **SMTP odchozí pošta**  | Admin → Nastavení → SMTP host/user/pass/odesílatel.                                          |
| **SMS (SmsManager)**    | `.env` řádek `SMS_API_KEY=` + `SMS_SENDER=`, pak `php artisan config:cache`.                 |
| **FIO API tokeny**      | Admin → Bankovní účty → vyber účet → vyplnit token (per účet, ne globálně).                  |
| **Ceník/VOP přílohy do post-sign emailů** | Zkopíruj `cenik.pdf`, `vop.pdf` do `/var/www/html/freenetis-laravel/storage/app/private/contracts/` a v admin → Nastavení → Smlouvy uveď cesty. |

---

## Migrace ze starého (Kohana) serveru

Vyexportuj dump na starém serveru:

```bash
# Plný dump s daty:
mysqldump --opt --single-transaction --skip-lock-tables \
    -u USER -pPASSWORD freenetis | gzip > freenetis-prod.sql.gz

# (volitelně) Contracts DB samostatně, pokud ji máš:
mysqldump --opt --single-transaction --skip-lock-tables \
    -u USER -pPASSWORD contractsdb | gzip > contractsdb-prod.sql.gz
```

Přenes na nový server a v setup wizardu uploadni `freenetis-prod.sql.gz`. Wizard:
- naimportuje main DB ze tvého dumpu
- automaticky doimportuje **prázdné schéma** pro `contractsdb` z repa, pokud ji nemáš (Kohana nemá smlouvy — Laravel je má)
- spustí inkrementální Laravel migrace nad importovanými daty
- spustí ACL seedery (přidá Gpon / SMTP exceptions / Contracts ACL pravidla)

Velikost dumpu: bez limitu (PHP+Apache jsou nakonfigurované na 2 GB upload, import streamuje přes pipe).

---

## Architektura

| Vrstva               | Implementace                                                                |
|----------------------|------------------------------------------------------------------------------|
| Web server           | Apache 2.4 + mod_proxy_fcgi → PHP-FPM (Unix socket)                          |
| PHP                  | 8.3 (Ubuntu 24.04) / 8.4 (Debian 13), opcache enabled                        |
| Framework            | Laravel 13.3                                                                  |
| DB main              | MariaDB, connection `mysql` v `config/database.php` — legacy Kohana schema   |
| DB contracts         | MariaDB, connection `contracts` — pure Laravel schema pro elektronické smlouvy |
| Cache / sessions     | File-based (`storage/framework/cache`, `storage/framework/sessions`)         |
| Auth                 | Custom `App\Auth\FreenetisUserProvider` (bcrypt + legacy SHA1/MD5 fallback s auto-rehash) |
| ACL                  | Kohana-style nested-set tabulek (`axo`, `aco`, `aro_groups`, `axo_map` …)    |
| PDF                  | mpdf 8 (rendering), FPDI + TCPDF (digitální podpis přes PFX)                  |
| SMS                  | SmsManager API + interní fronta (`sms_messages` tabulka)                     |
| Mail                 | Symfony Mailer (Laravel default) + interní fronta (`email_queues` tabulka)   |

### Důležité skripty / artefakty

```
scripts/
├── deploy.sh                      Update existující instance (pull + composer + migrate + clear)
└── install/
    ├── bootstrap.sh               One-liner curl|bash installer (kroky 1 + 2)
    ├── 01-install-system.sh       Systémové balíčky + Apache + PHP + MariaDB
    ├── 02-configure-app.sh        Aplikace + .env + DB + setup token + certbot
    ├── 03-install-phpmyadmin.sh   (volitelné) phpMyAdmin s IP allow-listem
    ├── templates/
    │   └── apache-freenetis.conf  Apache vhost template (Alias /freenetis, redirect)
    └── sql/
        ├── freenetis-bootstrap.sql.gz       45 KB — main DB schema + lookup data
        └── contractsdb-bootstrap.sql.gz     1.8 KB — contracts DB schema-only

app/Console/Commands/
├── FreenetisInstall.php           php artisan freenetis:install — bootstrap admin
├── MigrateReconcile.php           php artisan migrate:reconcile — registruje existující schéma
├── ImportBankStatements.php       Cron — FIO API automatický import
├── SendEmailQueue.php             Cron — odesílání emailů z fronty
├── SmsSend.php                    Cron — odesílání SMS z fronty
└── …
```

---

## Vývoj

```bash
git clone -b laravel-migration https://github.com/erotel/freenetis-laravel.git
cd freenetis-laravel

composer install
cp .env.example .env             # doplň DB credentials, APP_URL, ...
php artisan key:generate

php artisan migrate
php artisan db:seed --class=AclGponContractsSeeder
php artisan db:seed --class=AclSmtpExceptionsSeeder
php artisan freenetis:install   # interaktivně vytvoří admin
php artisan serve
```

Po každém `git pull` v dev: `php artisan optimize:clear` (jinak Laravel
nevidí nové `config/*.php` ani upravené blade šablony).

### Tasking

```bash
# Cron / scheduler (lokálně)
php artisan schedule:work     # běží v popředí, simuluje produkční cron

# Code style (Laravel default — pint)
./vendor/bin/pint

# Testing
php artisan test
```

---

## Bezpečnost

Auditováno ve čtyřech průchodech (Auth/ACL, Public input, XSS/Leak, Crypto/Perf).
Všechny nálezy **Critical** opraveny v commitech `06266d6` (security batch) a v navazujících
fixech (CONTRACTS_TOKEN_SECRET guard, SNMP creds redakce, OTP test mode env-gate, atd.).

Klíčové opatření:
- `CONTRACTS_TOKEN_SECRET` má guard ≥ 32 znaků (jinak `RuntimeException` při bootu)
- `OTP_TEST_MODE` je hard-gated na `app()->environment() !== 'production'`
- Login throttle `5,1`, forgot-password throttle + token TTL 60 min + generic response (anti-enumeration)
- Setup wizard gated tokenem, automaticky se vypne po prvním dokončení
- phpMyAdmin (volitelný) jen za IP allow-listem

Security vulnerabilities pošlete prosím na **slezi2@pvfree.net**, ne přes veřejné GitHub issues.

---

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

FreenetIS application code: GPL v3 (zděděno z původního FreenetIS / Kohana projektu).
