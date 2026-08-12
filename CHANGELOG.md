# Changelog

Všechny významné změny FreenetIS Laravel portu (v2.x). Verze podle [SemVer](https://semver.org/),
formát podle [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/).

Verzi v souboru `config/version.php` bumpni samostatným commitem `chore: bump version to X.Y.Z`,
ať lze changelog snadno regenerovat přes `git log vX..vY --oneline`.

## [2.18.0] — 2026-08-12

### Added
- **Archivace tarifů/poplatků.** Nový příznak `fees.archived` umožňuje skrýt
  nepoužívaný tarif z nabídky při přiřazení poplatku členovi (i z výchozího
  výpisu), **bez mazání** — poplatek i historická přiřazení v `members_fees`
  zůstávají. Bezpečná, plně vratná alternativa k mazání (které by přes
  ON DELETE CASCADE smazalo i historii srážek). Tlačítko „Archivovat" se
  zobrazí jen u tarifů **bez aktivního přiřazení**; serverová pojistka archivaci
  aktivního tarifu odmítne.
- **Počet aktivních členů u tarifu + výpis.** Ve výpisu tarifů (`/fees`) přibyl
  sloupec „Aktivních" (počet členů, kteří tarif aktuálně mají) s **prokliknem na
  seznam** těch členů/zákazníků (`fees/{id}/members` — jméno s odkazem na detail,
  typ, platnost, poznámka).

### Changed
- **„Osvobozen od poplatku" lze přiřadit ručně.** Nabídka poplatků (scope
  `Fee::assignable()`) = běžné poplatky **+ „Osvobozen od poplatku"**
  (`special_type_id=2`), i když je systémový (`readonly`). `readonly` nově slouží
  jen jako ochrana proti editaci/smazání, ne jako zákaz přiřazení. Přerušení
  členství (`special_type_id=1`) a ostatní systémové poplatky zůstávají mimo
  nabídku.
- **České názvy systémových poplatků** (`special_type_id` 1–4): „Membership
  interrupt" → **Přerušení členství**, „Fee-free regular member" → **Osvobozen od
  poplatku**, „Non-member" → **Nečlen**, „Honorary member" → **Čestný člen**.
  Kosmetika — kód se řídí `special_type_id`, ne názvem.

### Database
- Migrace `…_add_archived_to_fees` (freenetis): sloupec `fees.archived`.
- Migrace `…_czech_names_for_system_fees` (freenetis): české názvy poplatků 1–4.

## [2.17.0] — 2026-08-12

### Added
- **Číslo smlouvy = ID člena.** `generateContractNo()` nově generuje
  `SML-<rok>-<ID_člena>` (konvence z původního importu) místo pořadového čísla.
  Jeden člen = jedna smlouva. Při vzácné kolizi s legacy smlouvou jiného člena
  (pár podepsaných smluv z 2026 mělo ještě „ujeté" pořadové číslo) se přidá
  suffix `-2`, aby se nespadlo na UNIQUE index `uq_contract_no`.

### Changed
- **Zrušení návrhu smlouvy ho maže.** `cancelContract()` nepodepsaný návrh
  (`draft`/`otp_sent`/`otp_verified`) rovnou smaže (dřív jen nastavil status
  `canceled` a mrtvé řádky se u člena hromadily a „spotřebovávaly" čísla).
  Uvolní se tím číslo (= ID člena) pro novou smlouvu. Child tabulky padnou přes
  ON DELETE CASCADE, do logu jde audit záznam. Podepsané/ukončené smlouvy
  zůstávají jako doklad (guard je nepustí).
- **Dynamická částka v upozornění na placení.** Zpráva „Upozornění na placení
  (zákazník)" (typ 6) měla částku natvrdo `320kč`; nahrazena placeholderem
  `{payment_amount}`, který se počítá z tarifu člena + dodatečných služeb
  (stejný zdroj jako QR platba). Text i e-mail.

### Fixed
- **Odkaz na VOP ve smlouvě.** V bodě 5.2 nové smlouvy odkaz na obchodní
  podmínky změněn z `…/smlouva-vop-ukonceni-smlouvy-dokumenty/` na
  `…/smlouva-vop-prilohy-ke-smlouve/`.

### Database
- Migrace `…_make_payment_amount_dynamic_in_reminder` (freenetis): `320kč` →
  `{payment_amount}` v `messages` typu 6 (idempotentní, má `down()`).
- Migrace `…_delete_canceled_contract_drafts` (contracts): jednorázově smazala
  historické zrušené návrhy (`status='canceled'`); `signed`/`terminated` i
  rozpracované `draft`/`otp_sent` ponechány.

## [2.16.1] — 2026-08-02

### Fixed
- **403 na povolených podsítích zákazníka.** Název podsítě byl proklik na její
  detail (`subnets.show`), který je jen pro admina — zákazník tak po kliknutí
  dostal „403 Forbidden". Nově se zákazníkovi název ukáže jako text, odkaz vidí
  jen admin s oprávněním.

### Changed
- **Přehlednější přepínání podsítí.** Ve výpisu povolených podsítí je sloupec
  **Stav** teď jen štítek (`✓ Zapnuto` / `✗ Vypnuto`) a ve sloupci **Akce**
  přibylo jasné tlačítko **Zapnout** (u vypnuté) / **Vypnout** (u zapnuté)
  místo malého ✓/✗ přepínače.
- **Stabilní pořadí podsítí.** Řádky se řadí podle `id` a při přepnutí on/off
  se už nepřehazují (dřív vypnutá podsíť skočila dolů).

## [2.16.0] — 2026-07-31

### Added
- **Dodatečné služby (poplatek za veřejnou IP).** Nevyužitý typ poplatku
  „Pokuta" přejmenován na **„Dodatečné služby"**. Přiřazují se členovi v jeho
  poplatcích (`members_fees`) a strhávají se měsíčně jako **samostatná
  transakce (type 6)** oddělená od tarifu — v převodech je pak jasně vidět, co
  je tarif a co služba navíc. Sčítá se **víc aktivních služeb**, platí stejné
  prepaid pravidlo (nedostatek kreditu → `payment_blocked`) a dohánění po
  platbě (`PaymentBackchargeService`). Nový `AdditionalServicesResolver` je
  jedna pravda pro strhávání, dohánění i zobrazení. Na kartě člena přibyl
  rozpis **„Dodatečné služby"** + **„Celkem měsíčně"** (tarif + služby).
- **Dodatek „dodatečná služba" ke smlouvě.** Samostatný typ dodatku
  (`contract_addons`, `type='additional_service'`) k podepsané smlouvě:
  **přidání** služby podepíše zákazník přes **SMS OTP** (vlastní podpisová
  stránka), **zrušení** vydá poskytovatel bez OTP a pošle zákazníkovi PDF
  e-mailem (snižuje jen jeho závazek). Účinnost = 1. den dalšího měsíce,
  číslování **„DODATEK č. X"** společné s dodatky změny tarifu. Admin obsluhuje
  z karty smlouvy (vytvořit, náhled/stáhnout PDF, poslat odkaz, vydat, smazat).
- **Dodatečné služby přímo ve smlouvě (nové smlouvy).** Nová smlouva ukazuje
  řádky **„Doplňková služba"** + **„Celková cena"** (tarif/sleva + služby),
  snapshotnuté do `contract_parties` (neměnné pro podepsané PDF).
- **UI karta člena / smlouva.** Na kartě člena přibyl rychlý odkaz **„Smlouva"**
  (k Povoleným podsítím/Zařízení); sekce dodatků z karty odstraněna — vše je
  nově pod detailem smlouvy. Legacy „nulový tarif" dodatek přejmenován na
  **„Dodatek ke smlouvě – umístění AP na nemovitosti"**. Sekce **„Historie"**
  (audit log událostí) se zobrazuje jen administrátorovi, ne zákazníkovi.

### Fixed
- **QR platba zahrnuje dodatečné služby.** Částka v QR (SPAYD) i v upozorněních
  je nově **celková měsíční = tarif + dodatečné služby**, ne jen tarif —
  shodně s tím, co reálně strhává `fees:deduct`.

### Database
- freenetis: `enum_types` id 39 `penalty` → `additional service` (recyklace
  nevyužitého typu poplatku).
- contracts: `contract_addons` += `service_name`, `service_price`,
  `service_action`; `download_tokens.file_type` += `service_addon`;
  `contract_parties` += `additional_services_json`, `additional_services_total`.

## [2.15.0] — 2026-07-30

### Added
- **Ceník tarifů (rychlost/cena).** Třída rychlosti (`speed_classes`) nově nese
  i cenu — editovatelná tabulka v **Nastavení → Finance → Ceník tarifů** i přímo
  v CRUD tarifů. Prázdná cena = použije se výchozí poplatek, `0` = zdarma.
- **Třístupňový resolver měsíčního poplatku** (`RegularFeeResolver`):
  **individuální → cena tarifu → základní**. Jedna pravda o pořadí, kterou
  sdílí strhávání (`DeductFees`), QR platba, dohánění po platbě
  (`PaymentBackchargeService`), práh upomínek (`NotificationActivation`) i
  zobrazení na kartě člena a v terénní appce (s popiskem, z jaké úrovně cena je).
  Slevy se řeší úrovní 1 (individuální poplatek), takže cena tarifu zůstává
  sdílená ceníková.
- **Cena ve smlouvě podle tarifu.** Nová smlouva bere základní cenu z tarifu
  (dřív natvrdo 320 Kč) a pod ní ukazuje **zvýhodněnou cenu** + do kdy platí,
  když má člen individuální poplatek. Vše snapshotnuté do smlouvy (neměnné).
- **Dodatek „změna tarifu".** Samostatné, opakovatelné dodatky
  (`contract_addons`) k podepsané smlouvě: snímají **původní → nový** tarif a
  cenu, účinnost = 1. den dalšího měsíce, číslování **„DODATEK č. X"** (navazuje
  i na legacy nulový-tarif addon). Admin je vytvoří v kartě smlouvy (náhled PDF,
  poslat odkaz k podpisu, stáhnout, smazat); zákazník podepíše elektronicky přes
  **SMS OTP** (vlastní podpisová stránka, token nese `cid`+`aid`, finalizace
  uloží podepsané PDF na řádek dodatku a pošle e-mailem). Legacy „nulový tarif"
  addon zůstává beze změny.

### Fixed
- Fallback poplatku mapuje čekající typy členů na cílové (žadatel/čekající
  zákazník 1, 18 → zákazník 2; čekající člen 17 → člen 90), takže smlouva/QR
  žadatele už neukazují 0 Kč, když tarif nemá ceníkovou cenu.

### Database
- freenetis: `speed_classes.price` (DECIMAL(10,2), nullable).
- contracts: `contract_parties.price_after_discount` + `discount_until`; nová
  tabulka **`contract_addons`** (snapshot dodatku vč. `addon_no`);
  `download_tokens` rozšířeny o `tariff_addon` + `addon_id`.

## [2.14.0] — 2026-07-27

### Added
- **DHCP relay režim exportu.** DHCP export umí generovat relay-based
  konfiguraci přímo, takže MikroTiky si ji stáhnou a naimportují samy — odpadá
  pomocný server, který dosud plný export ručně přepisoval (`interface`→`vlan`
  + `relay`, static-only role) a pushoval přes ssh/scp. Nové nastavení
  **`dhcp_relay_map`** (Nastavení → Síť), řádky `ID zařízení = rozhraní`:
  zařízení v mapě se v exportu přepne do relay režimu — `interface=<z mapy>`
  a `relay=<gateway subnetu>` (bere reálnou gateway z FreenetISu, robustnější
  než parsování názvu subnetu). Přidání další dvojice MK = přidání řádku.
  Dva servery v páru se liší URL parametrem `role`: default = primary
  (`authoritative=yes`, vlastní pool), `role=static` = druhý server
  (`authoritative=after-10sec-delay`, `address-pool=static-only`).
- **Per-konzument detekce změn DHCP exportu.** Umožňuje, aby stejné subnety
  četlo více DHCP serverů (redundantní primár + static-only MikroTik) přímo
  z FreenetISu. Dřív sdílený boolean `subnets.dhcp_expired` shodil první
  konzument a druhý už změnu neviděl (dostal 204). Nově export s **`?client=`**
  drží high-water-mark každého klienta zvlášť (`dhcp_changed_at` vs
  `dhcp_export_state`), takže každý MK vidí každou změnu právě jednou. Čas se
  zachytí před čtením stavu (žádná TOCTOU ztráta změny během exportu),
  mikrosekundová přesnost brání kolizi ve stejné vteřině. Zpětně kompatibilní:
  konzument bez `?client=` jede přes původní `dhcp_expired` beze změny.

### Database
- Migrace `add_per_client_dhcp_change_detection`:
  `subnets.dhcp_changed_at` (**DATETIME(6)**, timestamp poslední změny) + nová
  tabulka **`dhcp_export_state`** (`client`, `exported_at`) pro high-water-mark
  konzumentů.

## [2.13.0] — 2026-07-24

### Added
- **Zákazník vidí svá zařízení (self-access).** Běžný člen bez ACL `view_all`
  na `Devices_Controller` teď na svém profilu vidí tlačítko „Zařízení" a může
  otevřít seznam (`devices.by_user`) i detail (`devices.show`) **vlastních**
  zařízení. Přístup je omezen na zařízení pod stejným členem (`isOwnUser`).
  Detail zůstává read-only — úpravy, mazání, rozhraní, technici, login/heslo
  jsou dál gated přes ACL; odkazy do admin sekcí se zákazníkovi renderují jako
  prostý text (žádné 403 dead-endy).
- **Zákazník může proaktivně požádat o nové připojení.** Vedle stávající
  IP/SNMP cesty (banner „Zaregistrovat toto připojení") přibyl self-service
  formulář `connection-requests/new` (`requestNew`/`storeRequest`): typ/šablona
  zařízení (nepovinné) + poznámka. **MAC adresa je povinná** (bez ní technik
  zařízení v DHCP nedohledá) — když ji jde detekovat přes SNMP z aktuální IP,
  předvyplní se zamčená, jinak ji zadá zákazník. IP/subnet přiřadí technik až
  při schválení. Žádost končí jako Čekající na `/connection-requests`, admin ji
  schválí přes formulář zařízení (`create-from-cr`, prázdné hodnoty doplní).
  Tlačítka na stránce zařízení zákazníka, na „Žádosti o připojení" i na profilu
  člena.

### Changed
- **Layout zobrazuje `warning` flash.** Dřív se `session('warning')` tiše
  zahazoval (renderovaly se jen success/error/info) — proto self-service akce
  „nic nedělala". Přidán `m-alert-warning` blok.

### Database
- Migrace `make_connection_request_ip_nullable`:
  `connection_requests.ip_address` / `subnet_id` / `mac_address` → **nullable**
  (proaktivní žádost bez předem známé IP; MAC vynucuje aplikace, ne schéma).

## [2.12.0] — 2026-07-23

### Changed
- **Smlouva: „Místo připojení" se bere z adresy prvního zařízení člena.**
  Dřív se `service_full_address` (v PDF „Adresa místa připojení") plnil z adresy
  člena. Nově se přednostně vezme adresa **prvního zařízení** člena (nejnižší
  `devices.id` s vyplněnou adresou umístění); když člen žádné takové zařízení
  nemá, fallback na adresu člena. Adresa strany (bydliště/sídlo) zůstává z člena.

### Added
- **Ruční editace místa připojení u návrhu smlouvy.** Na detailu smlouvy
  (`/members/{id}/contract`) lze u stavu Návrh v sekci „Smluvní strana" ručně
  přepsat „Místo připojení" (`service_full_address`). Zapisuje se událost do
  historie. Přidán i řádek „Místo připojení" do zobrazení strany.

## [2.11.1] — 2026-07-23

### Changed
- **Adresa umístění zařízení: plný výběr Město / Ulice / Číslo popisné.** Místo
  jednoho read-only pole (jen „převzít z člena") mají formuláře zařízení
  (create/add/edit) samostatné selecty Město + Ulice (AJAX dle města) + Číslo
  popisné — jako u člena. Předvyplní se z vybraného člena, ale **jdou přepsat na
  libovolné umístění** (typicky páteřní/AP zařízení jinde než sídlo člena).
  U editace tlačítko „↻ Převzít z člena". Při uložení se z trojice najde nebo
  vytvoří `address_point` (`AddressResolverService::resolveAddressPoint`, sdílené
  body se nezdvojují).

### Fixed
- **Blade `@json()` neuzavřel víceřádkové pole** (`Unclosed '['`) v device
  formulářích — direktiva nebalancuje `[]`. Výpočet mapy člen→adresa přesunut do
  `@php` bloku, `@json` dostává prostou proměnnou.

## [2.11.0] — 2026-07-23

### Added
- **Adresa umístění zařízení ve formulářích.** Přidání i úprava zařízení
  (`devices/create`, `devices/add`, `devices/edit`) má nové pole „Adresa umístění"
  (`address_point_id`). U nového zařízení se **automaticky předvyplní podle
  vybraného člena** (i po vyhledání člena přes ID); u úpravy se ukáže aktuální
  adresa zařízení + tlačítko „Převzít z člena". Dřív se `address_point_id` přes
  tyto formuláře vůbec neukládal. Adresní bod se sdílí (odkaz na stejný
  `address_points` řádek jako člen), netvoří se duplikát.
- **ARES: automatické přidání chybějícího města/ulice.** Když načtení dat z ARES
  vrátí město nebo ulici, které v DB nejsou, nově se **automaticky založí**
  (`AddressResolverService`) — ARES je důvěryhodný zdroj. Platí pro obě lookup
  cesty (admin i veřejná registrace) i všechny tři formuláře (člen create/edit,
  registrace). Dřív chybějící město/ulice blokovalo uložení (`required|exists`)
  a admin je musel zakládat ručně. Ošetřen ořez názvu ulice na `varchar(30)`
  před hledáním i zápisem (jinak by dlouhé názvy tvořily duplikáty).

### Fixed
- **PHP 8.4 deprecation** v `DeviceController::createWithTemplate()` — implicitně
  nullable parametr `$userId` převeden na explicitní `?int`.

## [2.10.1] — 2026-07-20

### Fixed
- **Bývalí zákazníci s podepsanou smlouvou nebyli v `/contracts` vidět.** Členové
  ukončení ještě před zavedením stavu „Ukončená" měli smlouvu pořád `signed`,
  takže je seznam (skrývající bývalé) nezobrazil. Migrace zpětně překlopí
  podepsané smlouvy bývalých členů (typ 15/16) na `terminated` — objeví se
  v přehledu a stav odpovídá realitě. Smlouva zůstává jako doklad (PDF), zapíše
  se událost do historie.

## [2.10.0] — 2026-07-20

### Added
- **Ukončování smluv (výpověď).** Nový stav smlouvy `terminated` = „Ukončená"
  (rozšíření enumu `contracts.status`). Podepsanou smlouvu lze označit jako
  ukončenou:
  - **automaticky** při ukončení člena/výpovědi (`endMembership()` i „označit
    jako bývalého") — podepsané smlouvy člena přejdou na „Ukončená",
  - **ručně** tlačítkem „Označit jako ukončenou" na detailu smlouvy (datum + důvod).
  Ukončené smlouvy zůstávají jako právní doklad (PDF), jsou vidět v seznamu
  `/contracts` (i pro bývalé členy), mají vlastní filtr „Ukončené" a zapisují
  událost do historie.

### Changed
- **Řádný člen (typ 90) nemá smlouvu.** Na detailu člena i na stránce smlouvy se
  u typu 90 skryje „Vytvořit smlouvu"; vytvoření je blokované i na serveru
  (`ContractController::create`).
- **Odznak nepodepsaných smluv v menu** (`countUnsigned`) už nepočítá smlouvy
  bývalých členů (typ 15/16) — teď odpovídá seznamu `/contracts`.

### Fixed
- **Osiřelé návrhy smluv po smazání/ukončení člena.** Při ukončení člena i při
  trvalém smazání se jeho nepodepsané návrhy smažou (dřív „visely" a nafukovaly
  odznak). Podepsané/ukončené zůstávají. Child záznamy (parties/events/otps) se
  domažou přes `ON DELETE CASCADE`.
- **Jednorázový úklid** existujících osiřelých návrhů (migrace) — nepodepsané
  smlouvy bývalých členů a řádných členů (typ 90).

## [2.9.3] — 2026-07-16

### Fixed
- **FIO import padal na timeoutu (`Operation timed out after 30002 ms`).** FIO API
  občas (rate-limit / zátěž) drží spojení ~30 s, ale klient měl `CURLOPT_TIMEOUT`
  taky 30 s → odpověď se utnula těsně před cílem. Zvednuto na 90 s + přidán
  `CURLOPT_CONNECTTIMEOUT` 15 s (rychlé selhání při reálném výpadku sítě) a jeden
  retry při timeoutu s odstupem 35 s (kvůli limitu FIO 1 požadavek/30 s/token).
  `importPayments()` má stejné timeouty, ale bez retry (aby se platba neodeslala
  dvakrát).

## [2.9.2] — 2026-07-16

### Fixed
- **QR platba se v odeslaném e-mailu nezobrazila (rozbitý obrázek + PNG v příloze).**
  `EmailSenderService::sendOne()` (tlačítko „Znovu odeslat" i jednotlivé odeslání)
  přidával inline QR přílohu bez `->asInline()`, takže šla jako běžná příloha
  mimo `multipart/related` a `cid:payment_qr.png` v těle se nenavázalo. Doplněn
  `asInline()` — stejně jako už měl cron `email:send-queue` (`SendEmailQueue`).
- **Náhled e-mailu (`/email-queues/{id}/show`): rozbitý QR.** Prohlížeč `cid:`
  odkazy nevykreslí. Náhled teď `cid:<name>` inline příloh přepíše na URL
  attachment endpointu, takže admin vidí QR stejně jako příjemce.

## [2.9.1] — 2026-07-15

### Fixed
- **Smlouva — po podpisu: nevyplněný variabilní symbol.** E-mail s podepsanou
  smlouvou (typ 30) i dodatkem (typ 31) substituoval jen `{contract_no}`, takže
  `{variable_symbol}` a další member-placeholdery zůstávaly v šabloně
  nevyplněné. `renderContractEmail` teď plní přes `Message::buildPlaceholders`
  podle `member_id` smlouvy (vyplní i `{member_name}`, `{iban}`, `{balance}`, …).

### Added
- **QR platba i v upozornění na nedostatečnou výši konta.** Placeholder
  `{payment_qr}` doplněn do e-mailových šablon „Nedostatečná výše konta"
  (typ 5 zákazník / 25 člen) — dosud QR obsahovala jen upozornění na placení
  (typ 6/26). Idempotentní migrace, QR se vloží za řádek s variabilním symbolem.

## [2.9.0] — 2026-07-09

### Added
- **QR platba (QR Platba / SPAYD) v upozornění na placení i na detailu člena.**
  Nová služba `PaymentQrService` sestaví český platební QR kód: cílový účet
  podle typu člena (config `bank_account_member_type_<type>`, IBAN z DB nebo
  dopočet z čísla účtu + kódu banky), částku podle aktivního pravidelného
  tarifu a variabilní symbol z kreditního účtu. V e-mailových šablonách
  upozornění (typ 6 zákazník / 26 člen) placeholder `{payment_qr}` vloží QR
  jako inline (cid) obrázek — spolehlivě i v Gmailu (`SendEmailQueue` nově umí
  inline přílohy, sloupec `email_queue_attachments.inline`). Na `/members/{id}`
  pod blokem Adresa nová karta „QR platba" (responzivní SVG). Zpráva pro
  příjemce nese marker „QR Platba …", aby šlo z bankovního výpisu poznat, kolik
  plateb přišlo přes QR. Nové placeholdery `{account_number}`, `{iban}`,
  `{payment_amount}`. Přidána závislost `endroid/qr-code`.
- **Smlouvy: PDF náhled na admin detailu** + tlačítko vytvořit novou smlouvu
  po zrušení; refresh/cancel nepodepsané smlouvy a whitelist respektovaný v cronu.

### Fixed
- **DHCP: `subnet.dhcp` se automaticky nevypíná při editaci IP.** Metoda
  `syncSubnetDhcp` chybně odvozovala `subnet.dhcp` z `ip.dhcp` gateway IP (ta
  je u routerů ~vždy 0) a tiše vyřazovala subnet z DHCP exportu při jakékoli
  změně/smazání IP. Metoda odstraněna, `dhcp` zůstává ruční admin flag.
- **IP: `member_id` u IP navázaných na rozhraní se nuluje.** Vlastník se
  odvozuje ze zařízení; vlastní `member_id` přežíval změnu vlastníka a
  způsoboval duplicitu v `qos_json` a špatné směrování redirectů.
- **ACL: skrytí akcí a odkazů** na detailu člena i účtu bez příslušného oprávnění.
- **Auth: throttle loginu podle IP+login** místo jen IP, tišší validace zařízení.

## [2.8.0] — 2026-06-23

### Added
- **Přihlášení variabilním symbolem.** Pole „Uživatelské jméno" v login
  formu nově přijímá i VS — pokud je vstup čistě číselný, server ho přeloží
  přes `variable_symbols → accounts.member_id → MAIN_USER` a přihlásí
  skutečného uživatele. Per-username throttle drží originální vstup, takže
  rate-limit platí pro obě cesty. WebAuthn/biometrie beze změny.
- **Export přihlášky pro čekajícího člena (typ 17)** v member detailu.
  Čekající zákazník (typ 18) přihlášku nevidí — podepisuje elektronickou
  smlouvu jiným tokem.
- **Obnovení přerušeného členství tlačítkem.** Na detailu člena s aktivním
  `membership_interrupts` se zobrazí „↻ Obnovit přerušení" — dialog s volbou
  okamžitě / k zadanému datu. Backend v transakci: `leaving_date=9999-12-31`,
  `locked=0`, `payment_blocked=0`, ukončí `members_fees` přerušení (`deactivation_date`
  = effective_date − 1), zruší `end_after_interrupt_end`, smaže `messages_ip_addresses`
  pro IP daného člena a strhne tarifní poplatek za aktuální měsíc s ohledem na
  prepaid pravidlo — pokud chybí kredit, nastaví `payment_blocked=1` místo
  vytvoření záporné položky.
- **Samoobslužná stránka oznámení `/me/notifications`.** Každý přihlášený
  uživatel si sám může vypnout kanály (přesměrování / e-mail / SMS). V menu
  pod „Domů → Moje oznámení". Hodinová cron `notifications:activate` opt-out
  tvrdě respektuje a vynechané kanály započítá do log_queues.
- **Správa oznámení v editaci člena + indikátor na detailu.** Admin vidí
  3 checkboxy v editaci, na detailu badge se seznamem vypnutých kanálů.
  V hromadných notifikacích nový sloupec „Oznámení" + filtr „Souhlas
  s oznámením"; odhlášené řádky jsou žlutě podbarvené, submit confirm
  dialog počítá kolik členů kanál odhlásilo (admin v UI rozhoduje sám).
- **Vyhledávání akceptuje MAC adresu v běžných formátech výrobců.**
  `SearchController::normalizeMac()` strhne `:`, `-`, `.` a mezery; pokud
  zbyde přesně 12 hex znaků, převede na kanonický `XX:XX:XX:XX:XX:XX`
  uppercase (formát v DB). Pokrývá DCN/Linux `50-91-e3-11-29-ad`,
  Huawei `789a-18e2-05db`, Cisco `5091.e3ad.1129`, raw `5091e3ad1129`
  i kombinace. Pro neúplný vstup vrátí null a padne na původní LIKE.
  Použito v `/search` stránce i v ajax autocomplete.
- **Ukončení s vratkou — auto-odpočet budoucích srážek tarifu.** Když admin
  zadá `leaving_date` v budoucnu, cron `fees:deduct` ještě stihne strhnout
  tarif za každý deduct_day, který padne před datum vystoupení (`WHERE
  leaving_date > deduct_date`). Dialog teď spočítá kolik takových srážek
  nastane, automaticky o ně sníží předvyplněnou částku vratky a zobrazí
  žluté upozornění s rozpisem (`balance − N × monthlyFee`). Bere v úvahu
  individuální `members_fees` override (s prioritou), default `default_fee_member_type_<X>`,
  clamp deduct_day na poslední den v měsíci i jestli už dnešní cron proběhl.
  Pokud admin částku ručně upraví, JS přestane přepisovat.
- **Nepodepsaná smlouva — refresh údajů ze člena a zrušení.** Tlačítko
  „↻ Aktualizovat údaje ze člena" (pro `draft`) přepíše `contract_parties`
  snapshotem z aktuálních dat člena (adresa, VS, tarif, kontakty) — použije
  se po opravě údajů v editaci (typicky číslo popisné). Zachovává `contract_no`,
  audituje diff do `contract_events` jako `party_refreshed`. Tlačítko
  „✕ Zrušit smlouvu" (pro `draft`/`otp_sent`/`otp_verified`) nastaví
  `status='canceled'` a odemkne cestu k vytvoření nové smlouvy — u zrušené
  se objeví „+ Vytvořit novou smlouvu", které dostane další sekvenční
  `contract_no` a čerstvý snapshot.
- **Náhled PDF smlouvy na admin detailu.** Iframe s náhledem PDF se zobrazí
  automaticky u každé nepodepsané smlouvy (nezávisle na tom, jestli byl
  odeslán odkaz zákazníkovi). Admin si tak zkontroluje jméno, adresu, tarif
  a cenu **před** odesláním. Nový endpoint `GET /contracts/{id}/preview`
  chráněný auth+ACL, generuje PDF z aktuálního `contract_parties`.
- **Bílá listina teď zablokuje i automatické přesměrování.** Hodinový cron
  `notifications:activate` respektuje `members_whitelists` — když admin
  ručně smaže přesměrování dlužníka a přidá ho na bílou listinu, cron mu
  ho už příští běh nezapíše zpět. Zprávy s `messages.ignore_whitelist=1`
  (přerušení/zánik/kritické) whitelist ignorují — konzistentní s hromadným
  UI. Statistika do `log_queues` rozšířena o počet vynechaných členů.

### Fixed
- **Podpisový iframe na admin detailu smlouvy — už není blokován Chromem.**
  `SecurityHeaders` middleware posílal `X-Frame-Options: SAMEORIGIN` na sign.*
  routách, což při cross-subdoménovém deploymentu (admin × www) vedlo k blokaci.
  Sign.show/preview a addon varianty teď z toho jsou vyňaty (ochrana stojí na
  jednorázovém HMAC podpisovém tokenu). Zároveň vyhozen `sandbox` atribut
  z iframu — kombinace `allow-same-origin` + `allow-scripts` v některých
  verzích Chromu triggerovala chybu „load from frame with URL
  chrome-error://chromewebdata/" u vnitřního PDF `<iframe>`.
- **Příchozí platba s VS ukončeného člena se už nespáruje** na credit účet
  bývalého člena. Sloupec `variable_symbols.variable_symbol` je varchar
  a u ukončených má suffix `+U` (např. `12345+U`). MariaDB při porovnání
  varchar=int implicitně castuje, takže `WHERE variable_symbol = 12345`
  matchovalo i `"12345+U"`. Fix: porovnávat jako string. Platba ukončeného
  teď padá do neidentifikovaných převodů, kde ji admin vrátí přes „↩ Vrátit".
- **Neidentifikované převody — tlačítko „Zobrazit vše" skutečně vypne
  defaultní 30denní filtr.** Předtím Laravel `route()` zahazoval prázdné
  `?from=` parametry a default znovu naskočil. Použit `?all=1` sentinel.
- **Shrnutí smlouvy se neposílá čekajícímu členovi** (typ 17) při veřejné
  registraci. Shrnutí je smluvní dokument pro zákazníka; člen dostává
  přihlášku k podpisu jiným tokem.
- **Pohoda XML export — IČO sdružení v dataPack hlavičce.** Setting klíč
  `organization_identifier` v config tabulce neexistuje (správně je `ico`),
  XML proto generoval prázdný `ico=""` a Pohoda XML odmítala s hláškou
  „Tento balíček není určen pro tuto jednotku".
- **Pohoda XML — vratky jako opravný daňový doklad.** Vratka má v Pohodě
  být `invoiceType=issuedCorrectiveTax` (ne `creditNote` / `issuedCreditNotice`),
  s DPH 21 %, zápornými částkami, povinnými `dateTax`/`dateAccounting`/
  `symVar` a `invoiceSummary` s `priceHigh`/`priceHighVAT`/`priceHighSum`.
  Port Kohana modelu `Pohoda_Refund_Export_Model::build_xml`.
  Reexport command `pohoda:reexport-refunds` sdílí stejnou metodu.

## [2.7.0] — 2026-06-16

### Added
- **Věková kontrola 18+ při veřejné registraci** (`/registration`).
  Datum narození u fyzických osob (typ 17/18) musí být alespoň 18 let
  zpět — serverová `before_or_equal` validace s CZ chybovou hláškou,
  klientské `max` na date inputu + okamžitý hint přes `setCustomValidity`.
  Organizace (typ 3) jsou z kontroly vyloučené (jejich datum představuje
  datum vzniku).
- **Mobilní klávesnice pro datum místo nativního pickeru.** Na touch-only
  zařízeních (telefon, tablet — detekce `(hover: none) and (pointer: coarse)`
  + `maxTouchPoints`) se každý `<input type="date">` převede na text input
  s `inputmode="numeric"`, formátem `DD.MM.RRRR` a auto-doplňováním teček
  při psaní. Před odesláním formuláře se hodnota převede zpět na
  `YYYY-MM-DD`, takže backend nepotřebuje žádnou změnu validace. Desktop
  s myší nativní picker ponechává.
- **Birthday guard při vytvoření smlouvy** (`ContractController::create`).
  Pro fyzickou osobu bez IČO nelze vytvořit smlouvu, dokud hlavní uživatel
  nemá vyplněné datum narození — jinak by v PDF byla buňka „Datum narození
  (IČO, DIČ)" prázdná a smlouva nepoužitelná. Redirect na editaci uživatele
  s vysvětlující chybou.

### Fixed
- **Email už nemůže skončit v IČO/DIČ člena.** Validační regex
  `^[^@\s]*$` na `organization_identifier` a `vat_organization_identifier`
  ve všech třech vstupních cestách (admin create/update, public
  registration) odmítne hodnotu s `@` nebo whitespacem. CZ chybové
  hlášky vysvětlují požadovaný formát.
  Na formulářích doplněn `autocomplete="off"` na IČO/DIČ pole
  (typicky způsob, jakým browser/password-manager autofill předtím
  email do DIČ vyplnil), `inputmode="numeric"` na IČO, `type="email"`
  + `autocomplete="email"` na email — správná sémantika polí, takže
  prohlížeč je už nezamění.

## [2.6.0] — 2026-06-14

### Added
- **Field Mode — mobile-first UI pro techniky v terénu (`/field/*`).**
  Odlehčená podmnožina FreenetIS pro telefon: žádná nová role ani auth,
  využívá existující session i ACL. Obsahuje:
  - **Live vyhledávání** (`/field/search`) s debounce — hledá členy podle
    jména (víceslovně v libovolném pořadí), variabilního symbolu, IČO,
    adresy (ulice/č.p./obec přes composite), telefonu, e-mailu, IPv4/IPv6
    (přes řetězec `ip → iface → device → user → member`) i názvu zařízení.
    Zařízení mají ve výsledcích přímý odkaz na detail.
  - **Detail člena** — kontakt s `tel:` / `mailto:` / odkazem do Google Maps,
    finance (saldo, „zaplaceno do", měsíční poplatek), seznam zařízení,
    přidání poznámky.
  - **Detail zařízení** — IPv4 i IPv6, rozhraní s MAC (každá hodnota na
    vlastním řádku kvůli mobilu), vlastník.
  - **PWA** — manifest a ikony pro přidání na plochu, tmavý režim podle
    `users.settings`, touch targety ≥ 44 px.
- **Biometrické přihlášení (WebAuthn / passkeys)** pro klasické i Field UI
  (`lbuchs/webauthn`, tabulka `webauthn_credentials`). Passkey zaregistrovaný
  jednou funguje na obou loginech (rpId = doména). Podporuje **usernameless**
  přihlášení (bez zadávání jména — prohlížeč nabídne uložené passkeys).
  Správa vlastních zařízení na `/account/passkeys` (přidat/odebrat), odkaz
  z hlavičky klasického UI i z Field. Konfigurace v `config/webauthn.php`
  (`WEBAUTHN_RPID`, `WEBAUTHN_RPNAME`, `WEBAUTHN_USER_VERIFICATION`).
  Výzva je jednorázová (cache, 5 min), čítač podpisů detekuje klonování,
  na Androidu auto-retry při cold-startu Credential Manageru. Vyžaduje HTTPS.

### Changed
- **Sjednocené rozložení obou login stránek** — jednotné pořadí
  uživatelské jméno → heslo → „Přihlásit se heslem" → „Přihlásit biometrií".

## [2.5.1] — 2026-06-12

### Added
- **Tažitelné šířky sloupců v tabulce zařízení uživatele**
  (`/users/{id}/devices`). Úchyt na pravém okraji každého záhlaví
  (vizuálně značený `⋮`) — mouseclick + drag mění šířku sloupce,
  dvojklik na úchyt resetuje na výchozí. Šířky se ukládají do
  `localStorage` pod klíčem `m-col-widths:user-devices`, takže přežijí
  refresh i nové přihlášení. Místo dřívějšího hardcoded
  `max-width:150px` na buňce Název je teď `table-layout:fixed`
  + `<colgroup>` — ellipsis truncate zůstává, ale je řízený šířkou
  sloupce, ne fixní hodnotou.

## [2.5.0] — 2026-06-03

### Added
- **Kreditový (prepaid) model strhávání poplatků.** `DeductFees` cron nově
  pro každého kandidáta zkontroluje, jestli kredit pokryje měsíční poplatek.
  Pokud ano, strhne jako dřív; pokud ne, NEstrhává a nastaví flag
  `members.payment_blocked=1` + `payment_blocked_since`. Zákazník tím nepřejde
  do mínusu — má jen 0 nebo positivní zůstatek a flagované přesměrování.
  Entrance/device fees mají stejný balance check, ale bez flagu (jsou to
  splátky, ne tarif). Tři nové sloupce na `members`: `payment_blocked`,
  `payment_blocked_since`, `pending_termination`.
- **Auto-přesměrování flagnutých členů.** Hodinový cron
  `members:redirect-blocked` plus `PaymentBlockedRedirectService` vybírá
  podle typu člena: zákazník (2/18) → `messages.id=5` „Nedostatečná výše konta
  (zákazník)", člen (90/3) → `messages.id=114` „Nedostatečná výše konta
  (členové)". Email rozesílá existující `NotificationActivation` cron přes
  pravidla v `messages_automatical_activations`; predikát `getMembersToNotify`
  pro `DEBTOR_MESSAGE`/`DEBTOR_MESSAGE_CLEN` rozšířen o `OR payment_blocked=1`,
  ať flagnutí s balance ≥ 0 dostanou email i bez záporné bilance.
  Přepínatelné přes setting `payment_blocked_redirect_enabled`.
- **Dohánění poplatků po platbě.** `PaymentBackchargeService` se volá
  z `ImportController.handlePostImport` po identifikované příchozí platbě:
  prochází měsíce od `payment_blocked_since` chronologicky, strhává
  každé `deduct_day` pokud bilance pokryje, jinak se zastaví. Pokud dohnal
  celé období bez break (žádný dlužný měsíc nezůstal), reset flag a
  `pending_termination` + smaže přesměrování přes `PaymentBlockedRedirectService`.
- **Označení k ukončení smlouvy podle VOP.** Denní cron
  `members:mark-pending-termination` (work jen v `Setting('pending_termination_day', 14)`,
  default 14. den měsíce) projde flagnuté členy s `payment_blocked_since`
  v předchozím měsíci nebo dřívějším a nastaví `pending_termination=1`.
  Pošle e-mail adminovi (`admin_notification_email` / fallback
  `email_default_email`) se seznamem kandidátů (jméno, VS, dluh, dní v blokaci).
  Žádné auto-ukončení — admin schvaluje ručně ve `/members/pending-termination`.
- **Admin view `/members/pending-termination`.** Tabulka kandidátů s
  jménem, typem (Zákazník/Člen badge), VS, stavem účtu, datem blokace
  a počtem dní. Akce „Ukončit" linkuje na `endMembership` form s
  předvyplněným `leaving_date=dnes`. Nová položka v menu „Uživatelé →
  Kandidáti na ukončení (N)" s counterem.
- **Badge v seznamu členů a v detailu.** `members.index` ukazuje vedle
  kreditu oranžový tag „Blokováno" pro `payment_blocked=1` a červený
  „K ukončení" pro `pending_termination=1` (přebije „Blokováno"). Stejný
  badge v titulku detailu člena + samostatné pole „Blokace platby" s
  datem `payment_blocked_since`.
- **Settings → Finance → Kreditový model.** Tři nová pole:
  `payment_blocked_redirect_enabled` (přesměrovat při nedostatku kreditu),
  `pending_termination_day` (den měsíce pro mark cron, default 14),
  `admin_notification_email` (cíl pro e-mail s kandidáty na ukončení).
- **Jednorázové migrační commandy.** `members:migrate-to-prepaid` (vynuluje
  dluhy bývalých členů 15/16 transferem credit→operating, flagne stávající
  s mínusovou bilancí včetně `payment_blocked_since` = poslední moment, kdy
  bilance přestala být nezáporná). `members:reverse-blocked-deductions`
  smaže historické srážky od `payment_blocked_since` u flagnutých členů —
  bilance se vrátí na pre-deduct stav a `Account::getExpirationDate`
  („Zaplaceno do") sedí s prepaid logikou. Oba defaultně `--dry-run`,
  apply přes `--apply`.

### Changed
- **`endMembership` resetuje prepaid flagy.** Při ukončení členství
  (`MemberController::endMembership`) se navíc nastavuje `payment_blocked=0`,
  `payment_blocked_since=NULL`, `pending_termination=0` a smaže přesměrování
  přes `PaymentBlockedRedirectService->refreshForMember`. Předchozí bug:
  bývalí členové (typ 15/16) zůstávali v menu „Kandidáti na ukončení"
  jako duch (`pending_termination=1` se nezrušil).
- **Univerzální vyhledávání: zařízení podle adresy + subnet jako odkaz.**
  `SearchController` device sekce joinuje `address_points`/`streets`/`towns`
  přes `devices.address_point_id`, matchuje town/street/č.p. + multi-token
  composite (CONCAT_WS přes jméno + ulice + č.p. + obec). `DeviceController.index`
  rozšířen na stejnou množinu polí, aby odkaz „Otevřít všechny v seznamu →"
  z `/search` seděl. V detailu IP adresy je subnet teď `<a>` na detail
  subnetu.
- **Town/Street detail ukazuje počet členů a prázdných AP.** Nový artisan
  command `address-points:cleanup-orphans` (`--dry-run` / `--apply` /
  `--town=` / `--street=`) najde a smaže address_points bez vazby na
  `members`/`devices`/`members_domiciles`.

### Fixed
- **Pohoda export pulled June invoices into May export.** `pohoda:export-monthly`
  pro cílový měsíc filtroval jen `pohoda_status='new'`, bez horního omezení
  data → faktury vystavené v dalším měsíci pronikaly do exportu. Přidán
  `whereDate('date_inv', '<=', $monthEnd)` cap.
- **Pohoda export selhal s `mkdir(): Permission denied`.** Export
  hardcodoval `/var/www/html/freenetis/data/export/` (vlastník root, www-data
  nemůže psát). Přesunut do `storage_path('app/private/pohoda-exports/')`.
- **`default_fee_member_type_2/90` čteno jako Kč částka místo fee_id.**
  `NotificationActivation` četl setting jako float (25 Kč), ve skutečnosti
  je to FK do `fees` tabulky. Helper `defaultFeeAmount()` resolvuje
  přes `fees.fee`. Po opravě: typ 6 odběrné členy 434 → 626.
- **Vyhledávání „Stanislava Manharda 19" nevracelo nic.** Multi-token
  v `/search` matchoval jen `m.name`. Opraveno přes composite
  CONCAT_WS přes jméno + ulici + č.p. + obec.
- **Vyhledávání „určice" — univerzál 284, listing 3.** `MemberController`/
  `UserController`/`DeviceController` index nepodporovaly stejnou množinu
  polí jako `SearchController`. Sjednoceno.
- **Vyhledávání: SQL error `count(distinct DISTINCT m.id)`.** Builder
  s `->distinct()` + `->count(DB::raw('DISTINCT m.id'))` Laravel double-prefixoval.
  Opraveno na `->count('m.id')`.
- **Strop 20 výsledků na sekci v `/search`.** Bumpnuto na 50 + zobrazení
  „X z Y · Otevřít všechny v seznamu →".
- **Tmavé téma: částky/data v `m-metric-value` byly bílé pro mínusový
  kredit / prošlé „Zaplaceno do".** `[data-theme="dark"] .m-metric-value`
  s `!important` přebíjel `.green`/`.red`. Přidány specifické dark-mode
  overrides (`#4ade80` / `#f87171`).

## [2.4.0] — 2026-05-29

### Added
- **Odeslat PDF přihlášky / ukončení členství / výpovědi smlouvy e-mailem.**
  V detailu člena vedle dropdownu „Export PDF" přibyl druhý „Odeslat na e-mail"
  se stejnými volbami (typ 90: Přihláška + Ukončení členství, typ 2: Výpověď
  smlouvy). Po potvrzení dialogem se PDF vygeneruje, uloží do
  `storage/app/email-attachments/` a zařadí do `email_queues` jako příloha
  na první e-mail kontakt hlavního uživatele. Doručí scheduler
  (`email:send-queue`). Podpis e-mailu se bere z nastavení „Titulek stránky".
  Stejný ACL gate jako inline export.

### Fixed
- **Hromadné notifikace: hlášeno X, odesláno Y (truncated form).** Formulář
  na `notifications/members` posílal `redirection[id]`/`email[id]`/`sms[id]`
  jako N×3 hidden inputy. Pro 1000 členů (3002 inputů) PHP s defaultním
  `max_input_vars=1000` zbytek tiše zahodil a backend zpracoval jen prvních
  ~333 — typický projev byl „264 na odeslání → 120 odesláno". Akce nyní jdou
  v jednom JSON inputu `bulk_payload`; pro 2000 členů má POST ~21.5 kB a
  3 inputy (test prokázal úplné doručení 500+2000+200 akcí).

## [2.3.0] — 2026-05-27

### Added
- **Auto-přesměrování čekajících zákazníků (type=18) s nepodepsanou smlouvou.**
  Nová systémová zpráva (type=32) + `PendingCustomerRedirectService` jako
  jediný zdroj pravdy pro přesměrování. Hodinový bezpečnostní cron
  `members:redirect-pending-customers` (truncate + rebuild) a okamžité hooky:
  po podpisu smlouvy (type 18→2) se přesměrování zruší, při přidání IP adminem
  členovi typu 18 se nastaví hned. Přepínatelné přes
  `pending_customer_redirect_enabled`.
- **Předvyplnění data koupě u nového zařízení (jako Kohana).** Pole „Datum
  koupě" se ve formuláři předvyplní dnešním datem a uloží se i když zůstane
  prázdné (`store` i `storeWithTemplate`).

### Changed
- **Hromadné notifikace: filtr, potvrzovací dialog a dávkové odesílání.**
  Notifikace jsou ve výchozím stavu vypnuté; přidán filtr (typ / kredit /
  whitelist / přerušení), který nastaví aktivní řádky. Před odesláním se
  zobrazí potvrzovací dialog s počtem e-mailů. E-maily/SMS se vkládají
  po dávkách (chunk 500) mimo transakci a odesílají s limitem na běh
  (`email_send_batch_size`), aby 2000 mailů nezpůsobilo problém. Per-řádkové
  výběry nahrazeny informačními štítky řízenými filtrem.
- **Vyhledávání ukazuje celou adresu místo jen města.** V hlavním
  vyhledávání seznam zobrazuje „Ulice číslo, PSČ Město" místo samotného města.
- **Výběr vlastníka připojení sjednocen s Devices.** V `connection-requests`
  i `devices` se vlastník zobrazuje jako „Příjmení Jméno" z hlavního uživatele
  (type=MAIN_USER) a řadí se podle příjmení; fallback na `members.name`
  pro organizace.
- **Systémové zprávy přeloženy do češtiny včetně názvů.** Migrace překládá
  `messages.name` podle typu a zbývající anglické HTML/SMS texty (host
  (un)reachable, velký dlužník).
- **Čitelnější barvy stavů odchozích plateb na tmavém pozadí.** Tmavě modrá
  „Exportováno" (#00a) a další stavy nahrazeny jasnější paletou.

### Fixed
- **Individuální členský poplatek se bral globálně místo per člen.**
  Poddotaz pro `members_fees` v `DeductFees` měl globální `LIMIT 1` bez
  korelace — vracel jediný řádek pro celou DB, takže se individuální tarify
  ignorovaly a všem se strhával výchozí poplatek (a osvobození s 0 Kč byli
  neoprávněně zatíženi). Nově korelovaný poddotaz (`mf2.member_id = m.id`)
  bere nejvyšší-prioritní aktivní tarif každého člena; 0 Kč = osvobozený
  (nestrhává se). Odhaleno suchým náhledem scheduleru k 1. dni měsíce.

## [2.2.0] — 2026-05-22

### Added
- **Vyhledávání na seznamech faktur, VLAN, Veřejných portů, Účtů a Bank. účtů.**
  Pole `q` se vzorem ze stránky Smluv — LIKE přes textové sloupce (název,
  partner, IP, číslo účtu, IBAN, jméno člena, komentář…); pokud je vstup
  číslo, hledá taky v ID, invoice_nr/var_sym/member_id, 802.1Q tagu a
  v rozsahu portů. Existující filtry (type tabs na účtech, Vydané/Přijaté
  na fakturách) zůstaly funkční a sort/pagination si přes
  `request()->fullUrlWithQuery` drží aktivní `q`.
- **`pohoda:reexport-refunds` umí `--from`/`--to` a `--pdf-only`.**
  Účetní potřebovala odděleně PDF všech zákazníků a XML jen vybraného
  období. Původní `--month` filtroval obě věci stejně; nový rozsahový
  filtr + `--pdf-only`/`--xml-only` umožní každý výstup vyrobit zvlášť bez
  zásahu do `status` v queue.
- **`pohoda_status` na fakturách (idempotentní měsíční export).**
  Symetrie s `pohoda_refund_queue` — `invoices` mají nové sloupce
  `pohoda_status` (default `new`) a `pohoda_exported_at`. Migrace
  `2026_05_22_100000_add_pohoda_status_to_invoices` provedla backfill: vše
  s `date_inv <= 2026-04-30` (4118 řádků) označeno jako `exported`, květnové
  faktury (87 řádků) zůstaly `new` k odeslání. `PohodaExportService::exportInvoices`
  teď filtruje `WHERE pohoda_status='new'` (místo dřívějšího `date_inv`
  rangu) a po vyrobení XML hromadně překlopí ID várky na `exported`.
  Re-spuštění `pohoda:export-monthly --force` už nevyrobí duplicity. Seznam
  i detail faktury ukazují tag (`odesláno` / `čeká`) a v hlavičce seznamu
  je filtr „Pohoda: vše / čeká na export / odesláno".

### Fixed
- **Duplicitní `doc_number` v `pohoda_refund_queue` (5× v období 2026-02..05).**
  `MemberController::nextRefundDocNumber` filtroval přes `member_type =
  CUSTOMER/REGULAR`, ale PENDING_CUSTOMER (18), HONORARY (3) a FEE_FREE (6)
  dostávají zákaznický/členský formát se svým vlastním typem — MAX query je
  pak nevidělo a generovala stejnou sekvenci dvakrát. Filtr přes
  `member_type` zahozen (pattern `doc_number` LIKE/NOT LIKE už typ jednoznačně
  rozlišuje), MAX-query opatřena `lockForUpdate()` proti race conditions,
  migrace `2026_05_22_080000_add_unique_doc_number_to_pohoda_refund_queue`
  přidává UNIQUE index na `doc_number`. Existující duplicity přečíslovány
  ručně před spuštěním migrace.
- **Race condition v `InvoiceService::generateServicesInvoice`.** MAX-query
  pro nové `invoice_nr` běžela MIMO transakci a bez zámku — dva paralelní
  bank importy (cron + ručně) by mohly vyrobit stejné číslo faktury.
  Aktuálně žádné duplicity v DB nejsou (3594 faktur za 2026 sekvenčně, bez
  mezer), ale latentní riziko bylo reálné. MAX přesunuta dovnitř transakce
  s `lockForUpdate()`, migrace
  `2026_05_22_090000_invoices_invoice_nr_bigint_unique` mění
  `invoices.invoice_nr` z `DOUBLE` na `BIGINT UNSIGNED NOT NULL` a přidává
  UNIQUE index jako tvrdou pojistku.
- **Pole `street_number` brala 50 znaků bez regex validace** — uživatelé do
  něj při registraci psali celou ulici s číslem („Jana Zrzavého 3993/12"),
  pak v exportech dostával účetní dvojí cestu. Validace zúžena na max 15
  znaků s regexem `/^(ev\.?\s*č\.?\s*)?\d[\dA-Za-z\/\- ]*$/iu` (musí začínat
  číslicí, volitelný prefix „ev. č." pro evidenční čísla). Aplikováno
  v registraci i v admin create/edit; HTML `pattern` + `title` dělá
  user-friendly browser hint. Existující data nejsou migrována.
- **Členové se základním ACL viděli admin komentáře na svém profilu.**
  `members.comment` (interní poznámky adminu) a sekce „Komentáře k účtu"
  byly v `members/show.blade.php` renderovány bez ACL gate, případně
  jen pod `$canComment` (`new_all`). Přidán `$canViewComment =
  view_all` na `Members_Controller::comment` a obě místa nově gateována přes
  něj — kdo nemá `view_all`, komentáře vůbec neuvidí.

## [2.1.9] — 2026-05-20

### Fixed
- **Reset hesla padal s 500 (`SQLSTATE[22001] 1406 Data too long for column 'password_request'`)** —
  `users.password_request` byl legacy Kohana `varchar(10)`, ale
  `ForgottenPasswordController::store()` generuje 40znakový token přes
  `Str::random(40)`. Migrace
  `2026_05_20_120000_expand_password_request_for_token` rozšiřuje sloupec na
  `varchar(40)`. Stejný pattern jako fix `login_logs.IP_address` z 2.1.8.
- **Email pro reset hesla měl odkaz jako text místo aktivního linku** —
  `EmailSenderService::sendOne` posílá body přes `->html()`, ale tělo bylo
  plain text s `\n`. Přepsáno na HTML s `<p>`/`<a href>`, fallback text part
  vzniká přes `htmlToText` (pattern z `ContractService`).
- **Editace sdíleného `contacts` řádku měnila email/telefon všem napojeným
  uživatelům** — `ContactController::update` dělal in-place UPDATE na
  `contacts.value`, takže rodinný/firemní email se přepsal i ostatním. Pokud
  je řádek sdílený s ≥2 usery, kontakt se „forkuje" (detach od aktuálního
  usera → `firstOrCreate` vlastní řádek → attach). Při 1 userovi zůstává
  in-place UPDATE. Sdílené řádky se tak postupně rozpadnou na samostatné.

### Changed
- **Zapomenuté heslo — sdílený email: pošli reset, jen pokud zbude 1 aktivní
  uživatel.** Email může být v `contacts` ve více řádcích (legacy duplikáty)
  i jeden řádek může být napojený přes pivot na víc userů. Nový kód sbírá
  userIDs napříč všemi `contacts` řádky se zadaným emailem a filtruje na
  `members.locked != 1` (přístup do FreenetISu). Když je aktivní právě 1
  (typicky rodina, kde druhý člen už je „bývalý"), pošle se reset jemu.
  Jinak generic response (anti-enumeration).
- **Picker uživatele ve formulářích zařízení nahrazen pickerem člena.**
  `devices/{id}/edit`, `devices/add`, `devices/add/{userId}`,
  `devices/create-from-cr/{crId}` a `devices/create` (resource) teď v dropdownu
  ukazují **členy**, ne usery. Na save se přes `resolveMainUserId()` najde
  main user (type=1) zvoleného člena a uloží do `devices.user_id` — schéma
  beze změny, všech 3679 stávajících devices už koukalo na main usery.
  Třídění podle příjmení (`users.surname`, `users.name`), label „Příjmení
  Jméno (login)"; pro organizace s prázdným surname fallback na `members.name`.
  Legacy URL `devices/add/{userId}` zůstává funkční.
- **Hlavní vyhledávání umí slova v libovolném pořadí.** Dosud `LIKE
  '%query%'` na celém řetězci znamenalo, že „zatloukal martin" nenašlo
  „Martin Zatloukal" (stačí prohodit). `SearchController::index` i `ajax`
  teď multi-slovo dotazy tokenizují a aplikují AND-LIKE per token na
  `members.name` a `CONCAT(users.name, ' ', users.surname)`. Single-slovo
  dotazy beze změny.

## [2.1.8] — 2026-05-18

### Fixed
- **Login přes IPv6 padal s 500 (`1406 Data too long for column 'IP_address'`)** —
  legacy `login_logs.IP_address` byl `varchar(15)` (max IPv4 "255.255.255.255"),
  IPv6 adresa typu `2a07:9c0:17:1702:afd9:df4d:41d2:fbea` (39 znaků) insert
  shodila. Insert se ale volá až **za** `Auth::attempt` — session už je
  regenerovaná, takže po F5 chytne `RedirectIfAuthenticated` a uživatel skončí
  v dashboardu („po F5 to jede"). Migrace
  `2026_05_18_220000_expand_login_logs_ip_address_for_ipv6` rozšiřuje sloupec
  na `varchar(45)` (max IPv4-mapped IPv6 `::ffff:255.255.255.255`).
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
