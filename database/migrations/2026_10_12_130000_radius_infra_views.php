<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RADIUS views pro MAC-PD (sql_freenetis) a Kea (sql_kea4, sql_kea6mac) pod
 * migrací — definice 1:1 z produkce .54 (vznikly ručně). Krok 4c.
 * (`*_lineid_v` a `*_pppoe_v` už jsou v dřívějších migracích.)
 *
 * Čtou je FreeRADIUS na .54 a na Kea uzlech kea-a/kea-b z LOKÁLNÍ REPLIKY —
 * DDL z .54 se na ně replikuje. Proto:
 *  - view se přepíše JEN když se jeho definice liší (na .54 no-op → nic se
 *    nereplikuje, DEFINER zůstává). Porovnává se normalizovaná definice od
 *    serveru: očekávaný SELECT se uloží do dočasného view a jeho
 *    VIEW_DEFINITION se srovná s existujícím;
 *  - CREATE OR REPLACE zachová granty radius_ro (per jméno view, na replikách
 *    se nereplikují). NOVÉ jméno view potřebuje ruční GRANT na .54, kea-a, kea-b.
 * Viz app/Domains/Access/INFRASTRUKTURA.md.
 */
return new class extends Migration
{
    private const TMP = '__migration_view_probe';

    /** @return array<string, string> view => SELECT */
    private function views(): array
    {
        $kea4From = "from ((line_ids l join ip_addresses ip on(ip.iface_id = l.iface_id and ip.gateway = 0 and ip.ip_address like '10.%'))"
                  . " join subnets s on(s.id = ip.subnet_id and s.dhcp = 1 and s.ipoe = 1))";
        $macFrom  = 'from (ifaces i join ip6_addresses ip6 on(ip6.iface_id = i.id))';
        $mac6     = "replace(ucase(i.mac),':','')";

        return [
            // MAC → IPv6 PD (legacy MikroTik, sql_freenetis)
            'radcheck_v' => "select min(ip6.id) AS id,ucase(i.mac) AS username,'Auth-Type' AS attribute,':=' AS op,'Accept' AS value"
                . " {$macFrom} where i.mac is not null group by ucase(i.mac)",
            'radreply_v' => "select ip6.id AS id,ucase(i.mac) AS username,'Delegated-IPv6-Prefix' AS attribute,'=' AS op,ip6.ip_address AS value"
                . " {$macFrom} where i.mac is not null",

            // Kea DHCPv4 flex-id = remote-id + circuit-id (binárně) → Framed-IP, jen IPoE subnety
            'radcheck_kea4_v' => "select min(l.id) AS id,concat(l.remote_id,l.circuit_id) AS username,'Auth-Type' AS attribute,':=' AS op,'Accept' AS value"
                . " {$kea4From} group by concat(l.remote_id,l.circuit_id)",
            'radreply_kea4_v' => "select l.id AS id,concat(l.remote_id,l.circuit_id) AS username,'Framed-IP-Address' AS attribute,'=' AS op,ip.ip_address AS value"
                . " {$kea4From}",

            // Kea DHCPv6 hw-address (MAC bez dvojteček) → PD, jen jednoznačný prefix
            'radcheck_kea6mac_v' => "select min(ip6.id) AS id,{$mac6} AS username,'Auth-Type' AS attribute,':=' AS op,'Accept' AS value"
                . " {$macFrom} where i.mac is not null and i.mac <> '' group by {$mac6} having count(distinct ip6.ip_address) = 1",
            'radreply_kea6mac_v' => "select min(ip6.id) AS id,{$mac6} AS username,'Delegated-IPv6-Prefix' AS attribute,'=' AS op,min(ip6.ip_address) AS value"
                . " {$macFrom} where i.mac is not null and i.mac <> '' group by {$mac6} having count(distinct ip6.ip_address) = 1",
        ];
    }

    private function definition(string $view): ?string
    {
        return DB::table('information_schema.views')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $view)
            ->value('view_definition');
    }

    public function up(): void
    {
        foreach ($this->views() as $view => $select) {
            DB::statement('CREATE OR REPLACE VIEW ' . self::TMP . " AS {$select}");
            $expected = str_replace(self::TMP, $view, (string) $this->definition(self::TMP));
            DB::statement('DROP VIEW ' . self::TMP);

            if ($this->definition($view) !== $expected) {
                DB::statement("CREATE OR REPLACE VIEW {$view} AS {$select}");
            }
        }
    }

    public function down(): void
    {
        // Záměrně no-op: views čte živý RADIUS (.54 + Kea repliky), smazání by
        // shodilo IPoE/PD. Ruční stav před migrací byl stejný.
    }
};
