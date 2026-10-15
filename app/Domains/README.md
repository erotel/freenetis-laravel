# Domény (modulární monolit)

Nový a refaktorovaný kód se člení podle **domén**, ne podle typu souboru.
Pořád jeden repozitář, jeden deploy, jedna DB — jen s vynutitelnými hranicemi.

```
app/Domains/<Doména>/
    Actions/        jeden use-case = jedna třída (ApproveMemberAction)
    Repositories/   JEDINÉ místo s přístupem do DB pro agregát domény
    Events/         co se stalo (MemberApproved) — minulý čas, neměnná data
    Listeners/      reakce na event (SendApprovalEmail, WriteAuditLog)
    Exceptions/     doménové chyby (MemberNotApprovable)
    Support/        čisté pomocné třídy domény bez vlastního úložiště (CircuitIdParser)
    <Technologie>/  klient externího systému / specifická logika (Access/Kea, Access/Pppoe)
    <Doména>ServiceProvider.php   registrace eventů/listenerů domény
```

Plánované domény: `Members`, `Billing`, `Network`, `Access` (PPPoE/IPoE/RADIUS),
`Contracts`, `Notifications`, `Audit`.

## Konvence

**Repository** — zapouzdřuje legacy schéma (anti-corruption layer).
- Veškeré `DB::table()` / Eloquent dotazy domény patří sem, ne do controlleru ani akce.
- Metody pojmenované byznysově (`primaryEmail($memberId)`), ne SQL-ově.
- Vrací modely, skaláry nebo malé DTO — nikdy `Builder`.
- Tabulky, na kterých visí RADIUS views / igw / Kea, se mění jen přes repozitář
  a až když jsou pod migrací (viz roadmapa, krok 4).

**Action** — jeden use-case, jedna veřejná metoda `handle(...)`.
- Nezná `Request` ani session; dostává typované argumenty, vrací výsledek
  nebo vyhodí doménovou výjimku.
- ACL kontroluje controller (vstupní hranice), akce předpokládá oprávnění.
- Na konci dispatchne event (`MemberApproved::dispatch(...)`).

**Event / Listener** — vedlejší efekty (e-mail, audit, PPPoE, IP, cache) jsou
listenery, ne kód v akci. Nová featura = nový listener, ne zásah do core.
- Listenery běží **synchronně** (dokud vědomě nerozhodneme o frontě) — pořadí
  registrace v ServiceProvideru = pořadí provedení.
- Registrace explicitně v `<Doména>ServiceProvider`, žádný auto-discovery.
- Potřebuje-li volající výsledek listeneru, nese ho event v jednom zdokumentovaném
  poli (`IpoeReservationsChanged::$cacheFlushed`) — ne návratová hodnota
  (`false` z listeneru Laravel bere jako stop propagace).

**Controller** — tenký: ACL → validace (Form Request) → akce → redirect/flash.
- Form Requesty v `app/Http/Requests/<Doména>/`; hrubé ACL smí být v `authorize()`
  (běží před validací → 403 dřív než chyby formuláře). Musí volat
  `hasAccess`/`aclCheck` (přímo nebo přes vlastní helper) — jinak je routa
  pro `acl:coverage` / AclCoverageTest neošetřená.
- Jemná práva (skrytá pole) zjistí controller a předá akci jako `bool` —
  akce je vynutí i při podvrženém POSTu.

**Service** — zůstává pro technické utility bez vlastního agregátu (PDF, OTP,
QR). Existující `app/Services/*` se přesouvají do domén postupně.

## Pravidla práce

1. Každý commit **buď refaktoruje** (testy projdou beze změny asercí), **nebo
   mění chování** — nikdy obojí.
2. Test napřed: před refaktorem charakterizační test v `tests/Characterization/`
   (zachytí současné chování včetně podivností).
3. Strangler-fig: jedna doména v čase; refaktor jen když na featuru saháme kvůli
   byznysu, ne do zásoby.
4. Malé PR, každý řez samostatně revertovatelný.

**Fasáda při přesunu** — existující `app/Services/X` se nemaže naráz: zůstane
jako tenká fasáda delegující do domény (stávající volající i testy fungují),
volající se přepojují po jednom, fasáda nakonec zanikne.

**Kontrakty s infrastrukturou** — tabulky, nad kterými stojí RADIUS views / Kea
(`line_ids`, `pppoe_secrets`), mají kontrakt popsaný v docblocku repozitáře.
Ruční změny schématu na produkci vždy dorovnat idempotentní migrací.

Vzorové implementace: `Members/` (schválení člena), `Access/` (line-id, PPPoE,
DHCP export, eventy Kea/PPPoE), `Billing/` (měsíční srážka poplatků).
