<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `*_lineid_v` (MikroTik IPoE, FreeRADIUS sql_lineid) dostanou sloupec
 * `remote_id`, aby se dalo párovat podle dvojice (circuit-id, remote-id).
 *
 * Proč: TP-Link SG2008 za ONT posílá circuit-id jen jako název portu
 * („Gi1/0/1"), stejný na každém switchi — rozliší je až remote-id (MAC
 * switche). Dosud view klíčovalo jen circuit-id, takže dva switche v IPoE
 * subnetu by na stejném portu vrátily dvě Framed-IP (zákazník by mohl dostat
 * cizí IP). `line_ids` je unikátní na (circuit_id, remote_id) už od 2026-10-12.
 *
 * Dotaz v sql_lineid (kea-a/kea-b) vybírá přesnou shodu remote-id, jinak
 * mapování bez remote-id (OLT circuit, starší záznamy); mapování s JINÝM
 * remote-id nikdy. Viz app/Domains/Access/INFRASTRUKTURA.md.
 *
 * Sloupec navíc stávajícím dotazům nevadí (vybírají sloupce jménem), takže
 * migrace jde nasadit před změnou FreeRADIUS. CREATE OR REPLACE zachová granty
 * radius_ro; view se přepíše jen když se definice liší (jako 2026_10_12_130000).
 */
return new class extends Migration
{
    private const TMP = '__migration_view_probe';

    /** @return array<string, string> view => SELECT */
    private function views(bool $withRemote): array
    {
        $from = "from ((line_ids l join ip_addresses ip on(ip.iface_id = l.iface_id and ip.gateway = 0 and ip.ip_address like '10.%'))"
              . " join subnets s on(s.id = ip.subnet_id and s.dhcp = 1 and s.ipoe = 1))";
        $remote = $withRemote ? ',l.remote_id AS remote_id' : '';
        $group  = $withRemote ? 'group by l.circuit_id,l.remote_id' : 'group by l.circuit_id';

        return [
            'radcheck_lineid_v' => "select min(l.id) AS id,l.circuit_id AS username{$remote},'Auth-Type' AS attribute,':=' AS op,'Accept' AS value"
                . " {$from} {$group}",
            'radreply_lineid_v' => "select l.id AS id,l.circuit_id AS username{$remote},'Framed-IP-Address' AS attribute,'=' AS op,ip.ip_address AS value"
                . " {$from}",
        ];
    }

    private function definition(string $view): ?string
    {
        return DB::table('information_schema.views')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $view)
            ->value('view_definition');
    }

    private function apply(bool $withRemote): void
    {
        foreach ($this->views($withRemote) as $view => $select) {
            DB::statement('CREATE OR REPLACE VIEW ' . self::TMP . " AS {$select}");
            $expected = str_replace(self::TMP, $view, (string) $this->definition(self::TMP));
            DB::statement('DROP VIEW ' . self::TMP);

            if ($this->definition($view) !== $expected) {
                DB::statement("CREATE OR REPLACE VIEW {$view} AS {$select}");
            }
        }
    }

    public function up(): void
    {
        $this->apply(true);
    }

    public function down(): void
    {
        // Vrátit jde jen PO vrácení dotazu v sql_lineid na kea-a/kea-b
        // (nový dotaz sloupec remote_id potřebuje).
        $this->apply(false);
    }
};
