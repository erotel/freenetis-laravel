<?php

namespace Tests\Feature\Qos;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Export qos_json: rychlost per přípojné místo (povolená podsíť) přes
 * assignments[], se zachováním zpětně kompatibilního profilu člena.
 */
class QosJsonTest extends DatabaseTestCase
{
    private int $member;
    private int $memberSpeed;
    private int $overrideSpeed;

    protected function setUp(): void
    {
        parent::setUp();

        $m = DB::table('members')->whereNotNull('speed_class_id')->where('id', '>', 1)->orderBy('id')->first(['id', 'speed_class_id']);
        if (!$m) {
            $this->markTestSkipped('žádný člen s rychlostí');
        }
        $this->member = (int) $m->id;
        $this->memberSpeed = (int) $m->speed_class_id;
        $this->overrideSpeed = (int) DB::table('speed_classes')->where('id', '!=', $this->memberSpeed)->orderBy('id')->value('id');

        // Aby request prošel guardTrusted (testovací IP = 127.0.0.1).
        Setting::set('address_ranges', '127.0.0.1/32');
    }

    private function makeSubnet(): int
    {
        return DB::table('subnets')->insertGetId(['name' => 'test-qos']);
    }

    private function addIp(string $ip, int $subnetId): void
    {
        DB::table('ip_addresses')->insert([
            'member_id' => $this->member, 'subnet_id' => $subnetId, 'ip_address' => $ip, 'iface_id' => null,
        ]);
    }

    private function memberEntry(array $json): ?array
    {
        foreach ($json['members'] as $m) {
            if ((int) $m['member_id'] === $this->member) {
                return $m;
            }
        }
        return null;
    }

    public function test_ip_v_podsiti_s_vlastni_rychlosti_dostane_profil_podsite(): void
    {
        $subnetOverride = $this->makeSubnet();
        $ip = '10.253.0.1';
        $this->addIp($ip, $subnetOverride);
        DB::table('allowed_subnets')->insert([
            'member_id' => $this->member, 'subnet_id' => $subnetOverride,
            'speed_class_id' => $this->overrideSpeed, 'enabled' => 1,
        ]);

        $json = $this->getJson('/web-interface/qos-json')->assertOk()->json();
        $entry = $this->memberEntry($json);

        $this->assertNotNull($entry, 'člen musí být v exportu');
        // Zpětná kompatibilita: profil člena zůstává jeho vlastní.
        $this->assertSame($this->memberSpeed, $entry['profile_id']);
        // Nové: IP je v assignmentu s profilem PODSÍTĚ, ne člena.
        $assign = collect($entry['assignments'])->firstWhere('profile_id', $this->overrideSpeed);
        $this->assertNotNull($assign, 'musí existovat assignment s rychlostí podsítě');
        $this->assertContains($ip, $assign['ipv4']);
        // Odvozená IPv6 /56 (z 10.253.0.1) musí být ve STEJNÉ skupině jako IPv4.
        $this->assertContains('2a07:9c0:0:100::/56', $assign['ipv6'], 'IPv6 musí sdílet rychlost své IPv4');
    }

    public function test_ip_bez_vlastni_rychlosti_zdedi_rychlost_clena(): void
    {
        $subnetInherit = $this->makeSubnet(); // bez allowed_subnets override
        $ip = '10.253.0.2';
        $this->addIp($ip, $subnetInherit);

        $json = $this->getJson('/web-interface/qos-json')->assertOk()->json();
        $entry = $this->memberEntry($json);

        $this->assertNotNull($entry);
        $assign = collect($entry['assignments'])->firstWhere('profile_id', $this->memberSpeed);
        $this->assertNotNull($assign, 'IP bez override musí spadnout pod rychlost člena');
        $this->assertContains($ip, $assign['ipv4']);
    }

    public function test_ploche_pole_ipv4_zustava_pro_zpetnou_kompatibilitu(): void
    {
        $ip = '10.253.0.3';
        $this->addIp($ip, $this->makeSubnet());

        $json = $this->getJson('/web-interface/qos-json')->assertOk()->json();
        $entry = $this->memberEntry($json);

        $this->assertNotNull($entry);
        $this->assertContains($ip, $entry['ipv4'], 'staré ploché pole ipv4 musí IP obsahovat');
    }
}
