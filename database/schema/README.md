# Výchozí schéma (baseline)

`mysql-schema.sql` = struktura DB **produkce (.54)** bez dat + obsah tabulky
`migrations`. Legacy tabulky z Kohana FreenetIS nemají vlastní migrace — tohle je
jejich jediný popis v repu.

- Laravel ho načte **jen do prázdné DB** (`migrate` bez tabulky `migrations`),
  pak doběhnou novější migrace. Na .54/.59 se nikdy nepoužije.
- Používá ho `php artisan testing:refresh-db` pro testovací DB `freenetis_test`.

## Obnovení (po migracích, které mění schéma)

Z produkce, jen čtení:

```bash
ssh -p29 root@10.133.230.54 '
  O="--no-tablespaces --skip-add-locks --skip-comments --skip-set-charset --tz-utc"
  mysqldump $O --routines --no-data freenetis
  mysqldump $O freenetis migrations --no-create-info --skip-extended-insert --skip-routines --compact --complete-insert' \
| sed -E 's/\s+AUTO_INCREMENT=[0-9]+//I; s#/\*!50013 DEFINER=`[^`]+`@`[^`]+` SQL SECURITY DEFINER \*/##' \
> database/schema/mysql-schema.sql
```

`DEFINER` se odstraňuje, aby schéma šlo nahrát i neprivilegovaným uživatelem
(views pak patří tomu, kdo je nahrál). Produkční views a jejich granty viz
`app/Domains/Access/INFRASTRUKTURA.md`.

## Otisk a hlídání driftu (`fingerprint.json`)

`php artisan schema:check` porovná živou DB s `fingerprint.json` (tabulky,
sloupce vč. typu/NULL/default/collation, indexy, definice views; bez DEFINER
a AUTO_INCREMENT). Běží denně v 05:40 (drift → warning `schema.drift` v logu,
exit 1) a ručně po nasazení.

Po migraci, která mění schéma, otisk přegeneruj z DB postavené jen z repa:

```bash
php artisan testing:refresh-db
php artisan schema:check --write --database=freenetis_test
```
