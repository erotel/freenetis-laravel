# FreenetIS Laravel — přehled funkcí

Informační systém pro malé a střední ISP / community network. Laravel 13 port
původního Kohana 3.3 FreenetISu (legacy v1.x v repu `freenetis-kohana/`).
Zachovává původní databázové schéma a SQL pohledy, takže běží proti stejné
produkční DB.

> **Verze:** viz [`config/version.php`](config/version.php), changelog
> v [`CHANGELOG.md`](CHANGELOG.md). Stack: PHP 8.4, Laravel 13.3, MySQL/MariaDB.

---

## 1. Členové a uživatelé

### Členská základna
- Evidence členů (`members`) a jejich uživatelských účtů (`users`)
- Typy členů přes `MemberType` helper: zákazník, řádný člen, čekatel, bývalý
- Stavy: aktivní, přerušený, zrušený
- Hierarchie přes `Aro_groups` (Auditní komise, Představenstvo, Inženýři, ...)

### Registrace a žádosti o připojení
- Self-registrace přes `/register` (rate-limited 5/10min)
- Žádosti o připojení (`connection_requests`) — formulář s auto-detekcí MAC
  zařízení přes SNMP na bráně subnetu (Mikrotik + Huawei S6720)
- Schvalování žádostí, vytvoření zařízení z žádosti
- Export registrací (PDF/CSV) přes `members/{id}/registration-export/{type}`

### Životní cyklus členství
- Ukončení členství (`/members/{id}/end-membership`) — automaticky vyřadí
  IP adresy a zařízení, aktivuje redirect
- Restore (obnova) zrušeného členství
- Přerušené členství (`membership_interrupts`) — dočasná pauza, redirect
  na splash stránku
- Bývalí členové — denní cron `members:redirect-former` aktivuje redirect
  podle `leaving_date`
- Whitelist členů (`member_whitelists`) — výjimky z platebních pravidel

### Uživatelé a kontakty
- Vícenásobné kontakty na člena (email, telefon, ICQ — staré typy
  deprecovány migrací `cleanup_enum_types`)
- Změna hesla s ACL gate (uživatel mění své heslo s ověřením, admin mění
  cizí bez ověření — `current_password` jen pro self&!admin)
- Forgotten password flow přes email link s expirací
- Login logs (`login_logs`) — IP + timestamp každého úspěšného loginu
- Per-uživatel dark mode toggle

---

## 2. Finance

### Účetnictví (double-entry)
- Účty (`accounts`) s `account_attribute_id`: členský účet, vstupní účet,
  kreditní účet (221100), bankovní účet (221000), atd.
- Převody (`transfers`) jako základní účetní entita: `origin_id`,
  `destination_id`, `amount`, `datetime`, `text`
- Bankovní převody (`bank_transfers`) — nadstavba nad transfers, pojí se
  k bank statement importu
- Variabilní symboly (`variable_symbols`) na účet — primární ID pro spárování

### Bankovní integrace
- **Fio API** (`FioApiService`) — auto-import přes API token
  (zašifrovaný `Crypt::encryptString` v Settings)
- **CSV import** (`FioCsvParser`) — manuální upload bank statementu
- Bank statements (`bank_statements`) — měsíční výpisy
- Auto-download configurations (`accounts_bank_accounts.auto_downloads`) —
  pravidla, který účet se v kterou hodinu auto-importuje
- **Neidentifikované převody** — auto-detect podle VS, ruční přiřazení
  k členovi
- Refundy (`bank_transfers/{id}/refund`) — vrácení platby zpět odesílateli

### Faktury
- Generování faktur (`invoices`) s PDF přílohou
- Položky faktury (`invoice_items`)
- PDF storage migrace (`MigrateInvoicePdfStorage` command)

### Pohoda export (účetní SW)
- `PohodaExportService` generuje měsíční XML pro Pohodu
- Cron `pohoda:export-monthly` — 1. v měsíci v 6:00
- Refund queue (`pohoda_refund_queue`) pro reconciliation

### Poplatky a strhávání
- Členské poplatky (`fees` + `members_fees`)
- Vstupní poplatky
- Poplatky za zařízení
- Speed classes (`speed_classes`) — tarify rychlosti
- Cron `fees:deduct` (denně 00:03) strhává poplatky podle `deduct_day`
- Notifikace dlužníků: typ 5 (zákazník) / 25 (člen)
- Notifikace nízkého kreditu: typ 6 (zákazník) / 26 (člen)

### Odchozí platby
- `outgoing_payments` — schvalovací workflow (`approve` / `cancel`)
- Export do bankovního formátu (`outgoing-payments/export/{bankAccountId}`)

### Statistika
- Cashflow report (`stats/cashflow`) — měsíční přehled příjmů a výdajů
- Dashboard s klíčovými metrikami

---

## 3. Síťová infrastruktura

### Subnety, VLANy, IP
- Subnety (`subnets`) — IPv4 + IPv6 (`ip6_addresses`)
- VLANy (`vlans`) — pojené k iface nebo subnet
- IP adresy (`ip_addresses`) přiřazené k iface; gateway flag
- IPv6 adresy + RADIUS export
- Allowed subnets (`allowed_subnets`) — bypass redirect rules
- Connection places (`address_points`, `streets`, `towns`)

### Zařízení a rozhraní
- Zařízení (`devices`) s rolí: engineer / admin (přístupy)
- Rozhraní (`ifaces`) na zařízení s vlastní MAC, IP, VLAN
- Šablony zařízení (`device_templates`) — Mikrotik, UBNT, Huawei, …
- Cron `devices:cleanup-no-ip` — automatické vyřazení zařízení bez IP
- Topologie zařízení (`Devices_Controller#topology`)

### SNMP MAC detekce
- `SnmpMacDetector` service — auto-vyplnění MAC při vytváření zařízení
- Detekce přes `sysDescr`: Mikrotik RouterOS, Linux, Huawei VRP / S6720
- ARP MIB fallback chain: moderní `ipNetToMediaPhysAddress` (RFC 4293)
  → deprecated `atPhysAddress` (RFC 826)
- DHCP MAC lookup pro Mikrotik (`.9999.` enterprise OID)

### GPON modul (volitelný)
- OLT (`gpon_olts`) — Optical Line Terminals
- ONT (`onts`) — Optical Network Units
- Geografie ONT (GPS souřadnice, město)
- Gated middlewarem `gpon_enabled`

---

## 4. Veřejné endpointy pro infrastrukturu

Cestou `/redirection/*` — používá je router / firewall / QoS skripty
v síti pro real-time provoz. Žádné auth, IP-bounded ACL na úrovni firewallu.

| Endpoint | Účel |
|---|---|
| `allowed-ip-addresses` | Whitelist IP pro firewall |
| `unallowed-ip-addresses/{type?}` | Blacklist podle typu zprávy (dlužník/přerušení/…) |
| `allowed-ip6-addresses` | IPv6 ekvivalent |
| `ipv6-radius` | RADIUS export pro IPv6 |
| `qos-json` | QoS pravidla pro shaper |
| `public-port-forwards-json/txt` | Port forwarding mapy |
| `public-ip-nat-1to1-json/txt` | NAT 1:1 mapy |
| `smtp-exceptions-json/txt` | SMTP whitelist |
| `self-cancelable-ip-addresses` | IP s self-cancel právem |
| `redirected-ranges` | Aktuálně přesměrované rozsahy |

Routy nesou jak nové dashed URL, tak legacy underscore aliasy
(infrastruktura má URL natvrdo).

---

## 5. Smlouvy s elektronickým podpisem

Vlastní DB connection `contracts` (separátní DB). UI je v
[`app/Http/Controllers/Contracts/`](app/Http/Controllers/Contracts/).

### Admin strana
- Vytvoření smlouvy z dat člena přes `ContractService`
- Generování PDF (`PdfService`) — vyplněný formulář s členskými údaji
- Stav smlouvy (`contract_events`)
- Dodatky (addons)

### Klientská strana (public)
- Veřejný odkaz s tokenem: `/sign/{token}/contract`
- Preview PDF před podpisem
- OTP přes SMS (`OtpService`) — KlikniaVolej nebo SmsManager driver
  podle nastavení
- Finalizace podpisu → zapis do PDF
- Stažení podepsané smlouvy
- Stejný flow pro dodatky (`addon/*`)
- Ukončení smlouvy (`terminate`)

### Emaily
- Šablony jako systémové zprávy (typy 28–31):
  - `CONTRACT_SIGN_LINK` (28) — odkaz na podpis
  - `CONTRACT_ADDON_SIGN_LINK` (29) — odkaz na podpis dodatku
  - `CONTRACT_SIGNED` (30) — po podpisu
  - `CONTRACT_ADDON_SIGNED` (31) — po podpisu dodatku
- Placeholdery `{member_name}`, `{member_id}`, `{balance}`, …
  s HTML escapingem (XSS-safe)

---

## 6. Komunikace

### Zprávy (`messages`)
- Systémové zprávy (typy 1–8, 25–31) generované automaticky
- Uživatelské zprávy (typ 0) — manuální broadcast
- Self-cancel: členové si mohou sami odblokovat redirect
- Auto-substituce placeholderů (`{member_name}`, …)

### Email
- Email queue (`email_queues`) — async přes cron `email:send-queue`
- SMTP konfigurace v Settings (zašifrované heslo)
- Přílohy (`email_queue_attachments`)
- Subject prefix konfigurovatelný

### SMS
- SMS queue (`sms_messages`) — async přes cron `sms:send`
- Dva drivery: **KlikniaVolej** (default) + **SmsManager**
- API klíče zašifrované v Settings (per-driver: `sms_password3`, `sms_password5`)

### Notifikace
- Auto-settings (`message_auto_settings`) — pravidla, kdy spustit
  který typ zprávy (např. dlužník po 30 dnech)
- Cron `notifications:activate` (každou minutu) aplikuje pravidla
- Komentáře (`comments`) k požadavkům a pracím

---

## 7. Admin a nastavení

### Nastavení (`/settings`)
Záložky:
- **system** — základní (název, logo, …)
- **finance** — splatnosti, deduct days
- **email** — SMTP credentials
- **sms** — SMS driver + klíče
- **network** — redirect, allowed subnets
- **users** — registrace, password policy
- **gpon** — povolit modul GPON
- **smlouvy** — PDF šablony, podpisová certifikační autorita
- **registration** — self-registration parametry
- **sledovanitv** — externí TV provider

Citlivé klíče (FIO token, SMS hesla, SledovaniTV heslo, PDF sign pass)
**zašifrovány at-rest** přes `Crypt::encryptString` v `Setting` modelu.

### ACL (Access Control List)
- Tabulky `acl` + `aco` + `axo` + `aro_groups` + `*_map` (legacy Kohana)
- Hierarchie skupin přes `parent_id` (`aro_groups`)
- ACL service s in-memory + Cache layer (TTL 5 min, bump generation
  counter pro instant invalidaci)
- ACL admin UI: vytvoření pravidel, přiřazení skupinám
- Aro Groups management s členstvím uživatelů
- **Debug mód gated přes ACL** — middleware `EnableDebugForAdmins`
  zapne `app.debug=true` jen pro uživatele s právem
  `Debug_Controller#debug view_all` (default jen System administrators)

### Enum types
- Číselníky (`enum_types`) — typy kontaktů, organizací, MD/MO, …
- Admin UI pro editaci hodnot
- `deprecated` flag pro skryté hodnoty
- Cleanup migrace (`cleanup_enum_types`) odstranila 11 nepoužívaných kategorií

### Diagnostika
- Login logs s IP
- Log queue (`log_queues`) — strukturované audit logy
- Cron heartbeat (`CronHeartbeat`) — detekce výpadku crontabu
- Saved filters (`saved_filters`) — uložené hledání

### Setup wizard
- Middleware `EnsureSetupComplete` přesměrovává na `/setup`,
  dokud admin neudělá první konfiguraci
- Po dokončení smaže `storage/app/setup.token` → middleware no-op
- `freenetis:install` artisan command pro CLI install

---

## 8. Bezpečnost

### Auth
- Session-based login (login + password)
- Per-IP throttle 10/min na `POST /login`
- Per-username throttle 10 neúspěšných pokusů / 5 min (chrání proti
  distributed brute-force)
- Bez remember-me (z důvodu `users.remember_token` neexistence)
- Setup token gate před prvním adminem

### Šifrování at-rest
Citlivé klíče v `settings` tabulce zašifrované přes `Crypt`:
- `fio_api_token_bank_account_*`
- `sms_password*` (KlikniaVolej + SmsManager)
- `sledovanitv_password`
- `pdf_sign_pass`
- `email_password`
- `dhcp_api_token`

Backward-compat: `Setting::get()` má fallback na legacy plaintext
(`DecryptException` → vrátí raw value).

### CSRF
- Globální `validateCsrfTokens` middleware
- Výjimky jen pro public sign endpointy (`sign/*`) — chráněny
  podpisovaným tokenem v URL

### Public sign OTP
- Smluvní podpis vyžaduje SMS OTP — 6 cifer
- Rate-limited per phone number
- Expirace po 5 minutách

### XSS
- Blade default escaping `{{ }}`
- `Message::substitute()` HTML-escape placeholderů (XSS-safe i pro
  user input v admin templates)

---

## 9. Cron joby

Definováno v [`routes/console.php`](routes/console.php):

| Příkaz | Frekvence | Účel |
|---|---|---|
| `cron:heartbeat` | each minute | Failure detection |
| `email:send-queue` | each minute | Email odeslání z fronty |
| `sms:send` | each minute | SMS odeslání z fronty |
| `notifications:activate` | each minute | Auto-zprávy (dlužník/kredit) |
| `subnets:update-allowed` | each minute | Refresh allowed subnets |
| `bank:import-statements` | hourly :55 | Fio API auto-import |
| `members:redirect-expired-applicants` | hourly | Vypršené čekací doby |
| `fees:deduct` | daily 00:03 | Strhávání poplatků |
| `members:redirect-former` | daily 00:09 | Bývalí členové |
| `members:redirect-interrupted` | daily 00:09 | Přerušená členství |
| `sledovanitv:sync` | daily 03:30 | TV provider sync |
| `pohoda:export-monthly` | monthly 1. 06:00 | Účetní export |

---

## 10. CLI nástroje

| Command | Účel |
|---|---|
| `freenetis:install` | First-time install (DB + admin + setup token) |
| `migrate:reconcile` | Legacy port helper — naskočení Laravel migration history na produkční DB |
| `migrate-invoice-pdf-storage` | Přesun PDF z DB na storage disk |
| `devices:cleanup-no-ip` | Vyřazení zařízení bez IP |
| `sledovanitv:check-mismatch` | Audit konzistence vůči SledovaniTV |

---

## 11. Integrace s externími systémy

| Systém | Účel | Kód |
|---|---|---|
| **Fio Bank** | API + CSV import bankovních výpisů | `FioApiService`, `FioCsvParser` |
| **Pohoda** | XML export pro účetnictví | `PohodaExportService` |
| **ARES** | Lookup firmy podle IČO (veřejné i pro adminy) | `ares/lookup-public/{ico}` |
| **SledovaniTV** | Sync TV zákazníků (volitelný modul) | `SledovaniTvService` |
| **KlikniaVolej** | SMS gateway | `KlikniavolejDriver` |
| **SmsManager** | SMS gateway (alternativa) | `SmsManagerDriver` |
| **SNMP** | MAC detection na switchích | `SnmpMacDetector` |

---

## 12. Hledání

- Global search (`/search`) — fulltext napříč členy, IP adresami, zařízeními
- AJAX search (`/search/ajax`) — autocomplete v top baru
- Saved filters per user

---

## Co aplikace **NEdělá** (záměrně mimo scope)

- Reálná QoS / firewall implementace — FreenetIS jen exportuje JSON/TXT,
  vlastní pravidla aplikuje externí router (Mikrotik, Linux + iptables, …)
- Real-time bandwidth monitoring (občas přes externí Grafana/Cacti)
- Telco helpdesk / ticketing (existují external integrace)
- Mobile app (jen responsivní web)

---

## Pro vývojáře

- Multi-DB Eloquent: `mysql` (default, hlavní FreenetIS schema) + `contracts`
  (separátní DB pro smluvní modul)
- Blade views pod `resources/views/` rozdělené po doménách
- Modern CSS v `public/css/modern.css` — písmo +20% škála vůči Kohaně
- Setup token / install flow viz [`scripts/install/`](scripts/install/)
- Coding style: PHP 8 typed properties, named arguments, `Crypt`/`RateLimiter`
  místo vlastních implementací
