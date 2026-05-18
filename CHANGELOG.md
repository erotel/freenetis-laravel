# Changelog

Všechny významné změny FreenetIS Laravel portu (v2.x). Verze podle [SemVer](https://semver.org/),
formát podle [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/).

Verzi v souboru `config/version.php` bumpni samostatným commitem `chore: bump version to X.Y.Z`,
ať lze changelog snadno regenerovat přes `git log vX..vY --oneline`.

## [2.1.8] — 2026-05-18

### Fixed
- **`notifications:activate` neloggoval hodinový redirect dlužníků** — kvůli
  `insertOrIgnore` druhý běh se stejnými IP nepřidal 0 řádků → log entry se
  přeskočil (gate `if ($ipsRedirect)`). Admin pak v `log_queues` nevidél, že
  hodinová aktivace vůbec proběhla. Refactor kopíruje Kohana
  `Notifications_Controller::notify($truncate_redir=true)`: napřed `DELETE
  FROM messages_ip_addresses WHERE message_id = X`, pak `INSERT` pro
  aktuální dlužníky, log se píše vždy. Bonus: člen, který doplatil, hodinou
  vypadne z přesměrování; člen, co si redirect odklikl self_cancel, ho
  do hodiny dostane zpět, dokud je v dluhu
  ([NotificationActivation.php#L100](app/Console/Commands/NotificationActivation.php#L100)).
- **`subnets.dhcp_expired` se neflagoval ve všech IP CRUD místech** — DHCP
  server tak po některých změnách nedostal impulz k reloadu. Tři díry:
  `DeviceController::storeWithTemplate` (nová IP přes šablonu),
  `DeviceController::destroy` (mass-delete `ipAddresses()->delete()` netriggeruje
  Eloquent events) a `IfaceController::update` (při změně subnetu na IP se
  flagoval jen nový, ne starý). Všechny tři sbírají dotčené subnet IDs a
  po commitu volají `Subnet::setExpired()`. `IpAddressController` a
  `IfaceController::destroy` byly OK.
- **DNS servery slepené bez separátoru v Mikrotik DHCP exportu** —
  `dns-server=10.133.37.3710.133.37.38` místo `10.133.37.37,10.133.37.38`.
  Příčina: produkční `config.dns_servers` obsahoval literální `\n`
  (backslash-n) z legacy Kohana data migrace, ne skutečný LF. Parser teď
  normalizuje `\\n → \n`, splittuje na `[,;\s|]+` a každý element validuje
  přes `FILTER_VALIDATE_IP`.
- **DHCP lease-time `00:00:00`, když je v Nastavení prázdno** —
  `Setting::get('dhcp_lease_time', '10800')` aplikoval default jen na úplně
  chybějící klíč, ne na uloženou prázdnou hodnotu. Po cast na int teď
  fallback na 10800 i pro `≤ 0`.
- **Zaokrouhlení v rekapitulaci DPH na faktuře za přijatou platbu** —
  haléřová položka `code=ROUND` (vat=0) figurovala v sekci „Rekapitulace
  DPH v Kč" jako řádek s 0 % (a render hardcoded „21 %", takže to bylo
  dvojnásob matoucí). Není to zdanitelné plnění → vyřazena z `$vat_totals`.
  Sazba se navíc už nehardcoduje, čte se z dat.
- **Hlavní vyhledávání neumí hledat podle IPv6** — `2a07:9c0:8b:7200::/56`
  vrátil nic, IPv6 jsou v separátní tabulce `ip6_addresses`. Plný search
  i ajax dropdown teď LEFT-JOIN-ují `ip6_addresses`, results tabulka má
  nový sloupec IPv6.
- **IP přesměrované na `/redirection`, která není v ip_addresses, zobrazila
  „Vaše připojení je v pořádku"** — fn-redirector totiž posílá i
  neevidovaná zařízení na captive portal. Pro `$row=null` se teď ještě
  zeptáme, jestli IP **vůbec existuje** v `ip_addresses`; pokud ne, vrátíme
  msg type 3 „Neznámé zařízení" (Kohana parita).

### Added
- **Auto-sync IP ↔ allowed_subnets** — nová třída
  `App\Services\AllowedSubnetSyncService` mirror Kohana
  `Allowed_subnets_Controller::update_enabled`. Wired do IP CRUD míst
  (`IpAddressController` store/update/destroy, `DeviceController`
  storeWithTemplate/destroy, `IfaceController` update/destroy). Když členovi
  přibude IP, subnet se automaticky přidá do allowed (priority na začátek,
  enabled=1 pokud pod `allowed_subnets_counts.count` limitem). Když IP zmizí
  a v subnetu už žádnou jinou nemá, subnet se odebere. Předtím admin musel
  manuálně přes UI Povolené podsítě, jinak `UpdateAllowedSubnets` cron
  hned aktivoval redirect „nepovolená podsíť" (msg 7).

## [2.1.7] — 2026-05-18

### Fixed
- **`{variable_symbol}` se nenahrazoval v e-mailech dlužníkům** (zprávy 5, 6, 25, 26
  a uživatelské 114/115) — `Message::buildPlaceholders` znal jen `member_name`,
  `member_id`, `balance`, `entrance_date`, `leaving_date`, ale ne `variable_symbol`.
  V šablonách typu „doplať VS {variable_symbol}" tak v odeslaném e-mailu zůstalo
  prázdné místo. VS čtu jako `GROUP_CONCAT` z `variable_symbols` přes
  `accounts.id` člena (`account_attribute_id=221100`), stejně jako Kohana SQL
  v `users_contacts::get_contacts_by_member_and_type` ([Message.php#L94](app/Models/Message.php#L94)).
- **`notifications:activate` posílal e-maily Nx, když měla zpráva víc pravidel** —
  loop iteroval přes každé pravidlo a pro každé vytvořil zvlášť sadu e-mailů
  pro všechny členy. Při `--force` (catch-up po výpadku CRONu) tak msg 6 se
  3 pravidly poslal každému dlužníkovi 2× e-mail (rule 26 + 49 oba
  `email_enabled=1`), msg 115 se 2 pravidly taktéž 2×. Refactor agreguje
  `email/sms/redirect` flagy přes OR napříč pravidly (kopie Kohana
  `Scheduler_Controller::notification_activation`), takže jedna zpráva =
  jedna sada notifikací, ať má pravidel kolik chce. `send_activation_to_email`
  adresáti se taktéž deduplikují
  ([NotificationActivation.php#L52](app/Console/Commands/NotificationActivation.php#L52)).

## [2.1.6] — 2026-05-16

### Changed
- **Login IP throttle z 5/min na 10/min** — `routes/web.php:101`
  `throttle:5,1` → `throttle:10,1`. Kanceláře se sdílenou veřejnou IP
  (NAT, několik lidí současně) trefovaly limit při běžném používání.
  Per-username throttle (10 neúspěšných pokusů / 5 min) zůstává
  beze změny — pořád chrání proti distributed brute-force.

### Added
- **Debug mód pro adminy přes ACL** — místo globálního `APP_DEBUG=true`
  v `.env` (které leakne stack trace + ENV + SQL všem) je teď debug
  zapínán per-request middlewarem `EnableDebugForAdmins`, pokud má
  přihlášený uživatel ACL právo `Debug_Controller#debug view_all`.
  Default přiřazení: skupina **System administrators** (group_id=32).
  Admin si může v ACL panelu přidat/odebrat toto právo dalším skupinám
  bez deploye nebo .env úprav. Migrace
  `2026_05_16_155257_add_debug_acl.php` vytvoří axo + acl + mapy
  ([EnableDebugForAdmins.php](app/Http/Middleware/EnableDebugForAdmins.php),
  [bootstrap/app.php](bootstrap/app.php#L18)).

## [2.1.5] — 2026-05-16

### Fixed
- **SNMP detekce MAC adresy nefungovala na Huawei S6720** —
  `SnmpMacDetector::isCompatible` parsoval sysDescr regexem
  `/STRING: "?(.*?)"?\s*$/` bez `s` (dotall) modifikátoru. Huawei VRP
  vrací sysDescr na 4 řádcích (`S6720-30C-EI-24S-AC\nHuawei Versatile
  Routing Platform Software\n VRP (R) software,Version 5.170 …`),
  `.` newliny defaultně nematchuje → match selhal → `linux` driver se
  ani neaktivoval. Mikrotik (jednořádkový `RouterOS 6.x`) tím nebyl
  postižen, takže to vypadalo, že SNMP funguje. Regex teď používá flag
  `s`, detekce `linux` driveru navíc rozpozná `Huawei` / `VRP` /
  `S6720` substringem. ARP walk nově preferuje moderní
  `ipNetToMediaPhysAddress` (`1.3.6.1.2.1.4.22.1.2`, RFC 4293) a
  deprecated `atPhysAddress` (`1.3.6.1.2.1.3.1.1.2`, RFC 826) je jen
  fallback pro starší Linuxy
  ([SnmpMacDetector.php:118](app/Services/SnmpMacDetector.php#L118)).
- **Stránka detailu subnetu (`/subnets/{id}`) padala s `ParseError`** —
  inline pattern `@else Ne@endif` v `subnets/show.blade.php` a
  `device_templates/show.blade.php` se nekompiloval správně. Laravel
  Blade používá regex `\B@…` pro detekci direktiv, který nematchuje
  `@endif` přilepené k slovu (`Ne@endif`), ale následný
  `replaceFirstStatement` pak přes `strpos` chybně spotřebuje sousední
  standalone `@endif`. Výsledný kompilát končil literálním `@endif`,
  což PHP odmítlo s „unexpected end of file". Doplněn jediný znak
  mezery: `Ne @endif` (5× ve 2 souborech).

## [2.1.4] — 2026-05-15

### Fixed
- **OTP SMS na podpisové stránce smlouvy nešla odeslat** — `OtpService::sendSms`
  byl natvrdo na `SmsManagerDriver` + četl klíč z `sms_password5`, jenže
  na produkci je aktivní KlikniaVolej (driver id=3) s klíčem v `sms_password3`.
  Klikniavolej heslo se posílalo jako `x-api-key` na SmsManager API a vracelo
  „Access denied". Driver se teď instanciuje podle `Setting('sms_driver')`
  (KLIKNIAVOLEJ=3 nebo SMSMANAGER=5), stejně jako cron `SmsSend.php:113`
  ([OtpService.php:283](app/Services/Contracts/OtpService.php#L283)).
- **Datum narození chybělo v PDF smlouvy u fyzických osob** —
  `ContractService::createContract` neukládal `birthday` do `contract_parties`.
  PdfService má fallback `ico → birthday`, takže firma s IČO se v PDF vyplnila,
  ale FO s datem narození měla buňku „Datum narození (IČO, DIČ):" prázdnou.
  Birthday se teď bere z `users.birthday` hlavního uživatele
  ([ContractService.php:83](app/Services/ContractService.php#L83)).
- **U nově registrovaných zákazníků se telefon zobrazoval s labelem „Nečlen"** —
  `RegistrationController` v self-registraci ukládal `contacts.type=5`, což
  v `enum_types` odpovídá row id=5 = „Nečlen" (member type, ne contact type).
  Mělo být 21 = „Telefon" (jako v adminském `MemberController.php:364`).
  Doplněna migrace, která dorovná historické řádky
  (`contacts.type=5 → 21`) a přejmenuje `enum_types.id=21` z „Phone" na „Telefon"
  (na dev DB ručně mimo commit, na produkci čekalo).
- **SNMP MAC detector** při registraci nového zařízení vyhazoval fatal Error,
  pokud chyběla PHP extension `snmp` — existující try/catch chytal jen `\Exception`,
  ne `\Error` z `Call to undefined function snmp2_get()`. Přidána defenzivní
  kontrola `function_exists`; bez extension se vrátí `null` (admin vyplní MAC
  ručně), do logu jednou za request warning
  ([SnmpMacDetector.php:47](app/Services/SnmpMacDetector.php#L47)).

### Added
- **Šablony smluvních emailů jako systémové zprávy** (typy 28–31 v `messages`
  tabulce) — admin je edituje v UI Sdělení stejným TinyMCE editorem jako
  „Žádost o členství schválena" atd. Nahrazuje hardcoded texty ve dvou
  místech (`ContractService::queueSignLinkEmail` + `PublicSignController`
  post-sign emaily). Typy:
  `28` Smlouva — odkaz pro podpis (placeholder `{url}`),
  `29` Dodatek smlouvy — odkaz pro podpis (`{url}`),
  `30` Smlouva — po podpisu (`{contract_no}`),
  `31` Dodatek smlouvy — po podpisu (`{contract_no}`).
  Pokud řádek v `messages` chybí nebo má prázdné `email_text`, padá to na
  původní hardcoded text — žádný silent failure podpisového flow.

### Docs
- README ENV override one-liner instalace měl chybný tvar
  (`VAR=… curl … | sudo bash` — proměnné se k bootstrap.sh nedostaly).
  Opraveno: `curl … | sudo VAR=… bash`. Ověřeno smoke testem.

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
