# Access — kontrakt s infrastrukturou (RADIUS / Kea)

Stav zmapovaný 2026-10-12 (Krok 4a roadmapy) na .54 (produkce), .59 (test) a Kea
uzlech kea-a (10.133.230.22) a kea-b (10.133.230.23). **Produkce .54 = pravda**;
.59 se k ní dorovnává migracemi.

## Topologie

```
                        ┌──────────────── .54 MariaDB (master, binlog MIXED) ─────────────┐
 FreenetIS app ──RW──▶  │ freenetis.*  (line_ids, ip_addresses, ip6_addresses, ifaces,    │
                        │              pppoe_secrets, subnets …  + rad*_v views)          │
 FreeRADIUS .54 ──RO──▶ │ radius_ro@localhost:   radcheck/radreply _v, _pppoe_v, _lineid_v│
                ──RW──▶ │ radius_acct@localhost: radacct, line_id_seen                    │
                        └──────────┬──────────────────────────────────────▲───────────────┘
                     replikace     │ replicate-wild-do-table=freenetis.%  │ sql_acct (RW)
                     (vč. DDL)     ▼                                      │ radius_acct@.22/.23:
              ┌──── kea-a / kea-b: lokální replika freenetis ────┐        │ radacct, line_id_seen,
 Kea DHCPv4 ─▶│ FreeRADIUS :18122 site kea4 → sql_kea4 (127.0.0.1)│────────┘ line_id_pending
 Kea DHCPv6 ─▶│ FreeRADIUS :18120 site kea6 → sql_kea6mac         │
 MK BRAS ────▶│ FreeRADIUS site freenetis-pd → sql_lineid,        │
              │   sql_freenetis, sql_pppoe (vše 127.0.0.1, RO)    │
              └───────────────────────────────────────────────────┘
```

- **Čtení** RADIUSu na Kea uzlech jde z **lokální repliky** (`radius_ro@127.0.0.1`),
  ne z .54. Granty `radius_ro@10.133.230.22/23` na `*_kea*_v` na .54 jsou pozůstatek.
- **Zápis** (accounting, self-learning) jde vždy na **.54** (`sql_acct`, `radius_acct`).
- Views se na repliky dostávají **replikací DDL** z .54 — `CREATE OR REPLACE VIEW`
  na .54 se propíše i na Kea uzly (ověřeno: definice na replikách = .54).

## Views (čte FreeRADIUS)

| View | Klíč `username` | Vrací | Čte | Kde |
|---|---|---|---|---|
| `radcheck_v` / `radreply_v` | `UPPER(mac)` | `Delegated-IPv6-Prefix` | sql_freenetis (.54, kea-a/b) | .54 = .59 |
| `radcheck_pppoe_v` / `radreply_pppoe_v` | PPPoE username | heslo, Framed-IP, v6 | sql_pppoe | .54 = .59, **v migraci** |
| `radcheck_lineid_v` / `radreply_lineid_v` | circuit-id (bin) | Framed-IP | sql_lineid (MK BRAS) | .54 = .59, **v migraci** |
| `radcheck_kea4_v` / `radreply_kea4_v` | `CONCAT(remote_id, circuit_id)` (bin) | Framed-IP (jen `dhcp=1 AND ipoe=1`, gateway=0, `10.%`) | sql_kea4 (Kea v4 flex-id) | **.59 starší** (jen circuit_id) |
| `radcheck_kea6mac_v` / `radreply_kea6mac_v` | `UPPER(mac)` bez `:` | `Delegated-IPv6-Prefix` (jen MAC s právě 1 prefixem) | sql_kea6mac (Kea v6 hw-address) | **jen .54** |
| ~~`radcheck_kea6_v` / `radreply_kea6_v`~~ | circuit-id | `Delegated-IPv6-Prefix` | nikdo (PoC) | smazáno z .59 2026-10-15 |

Dotazy modulů: `sql_kea4` a `sql_lineid` hledají `HEX(username) = <hex z RADIUSu>` —
username je **binární** (surové bajty option82), proto:

## Tabulky — kontrakt

- **`line_ids.circuit_id` / `remote_id` = `VARBINARY(255)`, `remote_id NOT NULL DEFAULT ''`**
  (.54). Remote-id bývá binární (MAC switche/OLT) — `varchar utf8mb4` na .59/repu by
  takové bajty odmítl/poškodil a `CONCAT` ve `*_kea4_v` by nesedl. → **4b**.
- `line_id_seen` — staging self-learningu. Píše `freenetis-pd` (.54 bez remote-id,
  Kea uzly s `remote_id_hex`), čte/označuje `lineid:sync` (LineIdRepository).
- **`line_id_pending`** — jen .54, mimo migrace i aplikaci. Píše site `kea4` na Kea
  uzlech pro **neznámý** circuit (Kea klient bez rezervace). 7 řádků, poslední
  2026-09-30. Aplikace ho dnes nečte — kandidát na onboarding UI, nebo sloučit
  s `line_id_seen`. Do té doby: pod migraci (create if not exists), **nemazat**.
- `radacct` — accounting, píše `sql_acct` (.54 i Kea uzly).

Views jsou pod migracemi (`2026_09_02_110000` lineid, `2026_10_12_130000` MAC-PD
+ Kea; pppoe v dřívější migraci) — definice = .54. `*_kea6mac_v` neprojde s
`ONLY_FULL_GROUP_BY`, který zapíná Laravel (strict) — aplikace ho nečte; dotaz v
testu jde se `sql_mode` serveru jako FreeRADIUS.

## Práva — co hlídat při migracích

- Granty `radius_ro` jsou **per view jménem** a na replikách se **nereplikují**
  (`mysql.*` mimo `freenetis.%`). `CREATE OR REPLACE` granty zachová; **nové jméno
  view = ruční GRANT na .54 + kea-a + kea-b**, jinak RADIUS dostane 1142.
- Views jsou `SQL SECURITY DEFINER`. DEFINER `freenetis@localhost` i `root@localhost`
  existuje na .54 i obou replikách (`freenetis` má `SELECT ON freenetis.*`) →
  migrace spuštěná aplikací (DEFINER = `freenetis@localhost`) projde i na replikách.

## .59 (test)

FreeRADIUS má navíc `sql_kea4`/`sql_kea6` a sites `kea4-test`/`kea-test` (PoC). Kea
na .59 neběží; `kea-test` sites zapisují do `line_id_pending`, která na .59 není.
