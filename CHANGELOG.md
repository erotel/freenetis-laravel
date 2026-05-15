# Changelog

Všechny významné změny FreenetIS Laravel portu (v2.x). Verze podle [SemVer](https://semver.org/),
formát podle [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/).

Verzi v souboru `config/version.php` bumpni samostatným commitem `chore: bump version to X.Y.Z`,
ať lze changelog snadno regenerovat přes `git log vX..vY --oneline`.

## [2.1.3] — 2026-05-15

### Fixed
- **End-membership** zamykal `members.locked=1` okamžitě, i když admin nastavil
  `leaving_date` v budoucnosti — člen ztratil přístup do portálu hned, ačkoliv
  mu internet ještě dojížděl do data odjezdu. Sjednoceno s redirectem internetu
  i auto-mazáním zařízení: `locked` se nastaví jen pokud `leaving_date <= dnes`,
  jinak ho v den D dorovná cron `members:redirect-former`
  ([MemberController.php:686](app/Http/Controllers/MemberController.php#L686),
  [RedirectFormerMembers.php:38](app/Console/Commands/RedirectFormerMembers.php#L38)).
  Pattern mirrors legacy Kohana `members.php:4076`.
- **Former members cron** — trojice bugů kolem budoucího `leaving_date`:
  Step 4 (aktivace redirectu) bral všechny FORMER bez ohledu na datum —
  doplněna podmínka `leaving_date <= today` + guard na sentinely
  `9999-12-31` / `0000-00-00`. Step 2 (auto-remove devices) byl gated na
  `$updated > 0`, takže pro členy ukončené přes `endMembership` form
  (už FORMER, `$updated=0`) se zařízení nikdy nesmazala — gate odstraněn,
  místo toho okno `BETWEEN today-7d AND today` (limituje blast radius
  retroaktivního smazání). Auto-remove navíc nepsal IP do `members.comment`
  jako endMembership form — doplněn stejný `[YYYY-MM-DD] ip1,ip2,…` prefix
  + mazání `ip6_addresses`, `ip_addresses` (`member_id` direct) a `subnets_owners`.

### Added
- **Nastavení → Registrace**: TinyMCE 7 WYSIWYG pro `registration_info`
  a `registration_license` (texty v PDF registrace). Předtím šly upravit
  jen přímo v DB. `SettingController::updateRegistration`, route
  `PUT /settings/registration`, stejná TinyMCE config jako `messages/_form`.

## [2.1.2] — 2026-05-14

### Fixed
- `freenetis/contracts` zobrazovalo `Člen #XXXX` u smluv ve stavu **návrh** —
  eager load `parties` filtroval na `active=true`, ale draft strany mají `active=0`
  dokud se nepodepíše. Filtr odstraněn ([ContractController.php:225](app/Http/Controllers/ContractController.php#L225)).
- `freenetis/devices/dhcp-servers` ukazovalo `Změněno` i u zařízení, kde žádný DHCP
  subnet nebyl expired — listing SQL agregoval `MAX(s.dhcp_expired)` i přes subnety
  s `s.dhcp=0` (zombie flag po vypnutí DHCP). Přidáno `AND s.dhcp = 1` v JOIN
  ([DeviceController.php:589](app/Http/Controllers/DeviceController.php#L589))
  + `SubnetController::update` při vypnutí DHCP vynuluje `dhcp_expired`,
  ať se v DB nehromadí nekonzistence.
- `freenetis/members/{id}/registration-export/registration` ukazoval v PDF
  HTML source (`<p><span style="…">…`) místo formátovaného textu — blade dělal
  `{!! nl2br(e($registrationInfo)) !!}` a escapoval HTML. Sjednoceno na `{!! ... !!}`.

### Changed
- Z `config.registration_license` odstraněn paragraf začínající "POZOR! Registrací
  se nerozumí…" (DB update mimo commit). GDPR + kontaktní paragraf zachován.
- DB cleanup: 272 zombie záznamů `dhcp_expired=1` na subnetech s `dhcp=0`
  vyresetováno (`UPDATE subnets SET dhcp_expired=0 WHERE dhcp=0 AND dhcp_expired=1`).

## [2.1.1] — 2026-05-14

### Fixed
- `notifications:activate` cron běžel každou minutu — debtor zprávy se rozesílaly **60× za hodinu**.
  Přidán "apply minute" gate (minuta `:10`, mirror Kohana `scheduler::AM_NOTIFICATION`),
  takže každé pravidlo vystřelí jednou za hodinu.
- Detail zprávy zobrazoval HTML source (`<p>Hello</p>`) místo renderu — `{{ }}` přepnuto na `{!! !!}`
  pro `text` a `email_text` (SMS zůstává escapované).
- `email_subject_prefix` na 4 místech vyráběl prefix natvrdo (`prefix . ' :: ' . name`) — pokud admin
  prefix vyprázdnil, subject začínal artefaktem ` :: <jméno>`. Sjednoceno přes
  `($prefix ? $prefix . ' :: ' : '')`.

### Added
- Zápis aktivace upozornění do `log_queues` (type=3 INFO, statistika v `exception_backtrace`) —
  mirror Kohana `Log_queue_Model::info`.
- TinyMCE 7 WYSIWYG editor (GPL/community, jsdelivr CDN) v editaci zprávy
  (`resources/views/messages/_form.blade.php`) — náhrada za Kohana TinyMCE 3.
- Datum narození (`users.birthday`) v sekci "Hlavní uživatel" detailu člena.

### Changed
- Enum typ kontaktu id=21 přejmenován z `Phone` na `Telefon` (DB `enum_types`).

## [2.1.0] — 2026-05-13

### Added
- **End-membership refund flow**: vratná faktura (dobropis) při ukončení členství,
  fronta `pohoda_refund_queue` pro export do Pohody, email s `{ucet}` placeholderem.
- **Payment emails** používají systémové Message id 9 (členové type 90)
  a id 122 (zákazníci type 2) místo hardcoded textů.
- **Web interface MikroTik compat**: legacy URL aliasy + HTTP fallback +
  podpora i18n prefix (`/cs/web_interface/...`) pro starší MikroTik klienty.
- **Contracts** — fulltext vyhledávání + filtr stavu v indexu, smlouvy bývalých
  členů/zákazníků vyloučené ze seznamu.
- **Devices** — artisan příkaz pro smazání zařízení bez IP adresy.

### Fixed
- `devices/by-user`: MAC a IP brát z libovolného rozhraní, ne jen z prvního.
- `web_interface`: `selfCancelableIpAddresses` kompatibilní s `ONLY_FULL_GROUP_BY`,
  IPv6 prefix/mask defaultovat i při prázdné hodnotě.
- `import`: post-import výjimky nesmí zastavit scheduler (běží další úkoly).
- `invoices`: `pdf_filename` rozšířen na `varchar(255)`, PDF přesunuto do
  `storage/app/private/invoices/` (citlivá data mimo `public/`).
- `fio-parser`: header rozpoznávat podle obsahu sloupců, ne blank separátoru;
  prázdné CSV bez data sekce neházet jako chybu.
- `refund-pdf`: Unicode v názvu (`refund-26ČL0011.pdf`).
- `end-membership`: tři bugy v refund flow + `{ucet}` + `{balance}` v emailu vratky.
- `messages`: `type=24` vyplněn u nové "Payment confirmation - customer".

### Changed
- UI: sortable sloupce v `transfers/by-account`, "Zařízení" button do horní lišty
  detailu člena, "Zpět" na detailu zařízení.
- Dark-mode: hardcoded `#f7f7f5` nahrazeno za CSS proměnnou `--fn-quote-bg`.
- `deploy`: git běží pod vlastníkem repa (obchází `safe.directory` ochranu).

## [2.0.1] — 2026-05-12

### Added
- **`bootstrap.sh`** one-liner installer (bez dvojího klonu).
- **`scripts/deploy.sh`** — git pull + composer + migrate + optimize:clear.
- **Artisan `migrate:reconcile`** — odsouhlasit migrace s reálným stavem schématu, integrované do `deploy.sh`.
- **Settings/Email**: upload `registration_summary_pdf` přímo z UI.
- **Settings/Smlouvy**: upload PFX certu a PDF příloh přímo z UI.
- **Email queues UI**: tlačítko "Načíst vše" + detail e-mailu s přílohami.

### Fixed
- `email_queues`: filtr `?all=1` zachován přes "Zrušit filtr".
- `settings/banka`: dropdown s účty filtrovat jen na účty spolku.
- `bank_transfers/unidentified`: vyfiltrovat interní převody (banka→dodavatelé apod.).
- `setup/wizard`: admin_email "not focusable" warning, `disabled` místo `required`.

## [2.0.0] — 2026-05-12

První oficiální release Laravel portu (v1.x byla legacy Kohana).

### Added
- **Zavedení verzování** — `config/version.php` jako single source of truth,
  zobrazeno ve footeru přes `config('version.string')`.

### Migration scope (od 2026-04-02 do 2026-05-12)
- **Auth & ACL** — Laravel auth, `FreenetisUserProvider`, `AclService`, Gate, ACL CRUD,
  ARO groups, pravidla.
- **Členové & uživatelé** — CRUD, registrace, kontakty (inline), adresy (towns/streets),
  uživatelské skupiny.
- **Síť** — devices, ifaces, ip_addresses, subnets, allowed_subnets, IP autocomplete,
  subnet autofill, unique validace.
- **Finance** — accounts, transfers (read-only), variable_symbols, faktury (CRUD + PDF),
  bank_accounts, bank_transfers (FIO CSV import), outgoing_payments.
- **Settings** — taby Banka/Email, BCC pravidla z DB, bank account routing podle typu člena.
- **Email queue** — `email:send-queue` artisan s BCC pravidly a přílohami.
- **Enum_types** — CRUD s ochranou systémových záznamů.
- **Install scripts** — `scripts/install/{01,02,03}` pro deploy na čistý Debian 13/Ubuntu 24.04
  (LAMP, FreenetIS app, phpMyAdmin), web first-run wizard místo bash promptu.
- **Security** — 8 Critical a 4 High findings z bezpečnostního auditu opraveny
  před prvním deployem.
- **Login logs** — read-only audit log.

### Notes
- v1.x existovala jako Kohana fork s vlastním versioning (`FREENETIS_VERSION = '1.1.28~pvfree'`),
  pokračuje paralelně v `freenetis-kohana/`. Tento changelog se týká **jen Laravel rewrite**.
