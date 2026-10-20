<?php

namespace Tests\Feature\Network;

use App\Domains\Network\Support\IpOwner;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Vlastník IP = přímo přiřazený člen (ip_addresses.member_id), jinak vlastník
 * zařízení — jedno pravidlo pro přesměrování, upozornění i přípojná místa
 * (dřív část systému přímé IP přehlížela, jiná je počítala oběma členům).
 */
class IpOwnerTest extends DatabaseTestCase
{
    private function member(): int
    {
        return (int) DB::table('members')->insertGetId(['name' => 'Char Owner', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    private function ifaceOf(int $member): int
    {
        $u = (int) DB::table('users')->insertGetId(['member_id' => $member, 'login' => 'charown_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'O', 'comment' => '']);
        $d = (int) DB::table('devices')->insertGetId(['name' => 'char-own', 'type' => 1, 'user_id' => $u]);
        return (int) DB::table('ifaces')->insertGetId(['type' => 1, 'name' => 'e', 'device_id' => $d]);
    }

    public function test_prime_i_zarizeni_a_prednost_primeho(): void
    {
        [$a, $b] = [$this->member(), $this->member()];
        $ifA = $this->ifaceOf($a);
        $onDevice = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.241.1.1', 'iface_id' => $ifA]);
        $direct   = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.241.1.2', 'member_id' => $a]);
        // IP na zařízení A, ale přímo přiřazená B → patří B (ne oběma)
        $override = (int) DB::table('ip_addresses')->insertGetId(['ip_address' => '10.241.1.3', 'iface_id' => $ifA, 'member_id' => $b]);

        $ids = fn (int $m) => app(RedirectionRepository::class)->memberIpIds($m)->map(fn ($i) => (int) $i)->sort()->values()->all();
        $this->assertSame([$onDevice, $direct], $ids($a));
        $this->assertSame([$override], $ids($b));
        $this->assertSame($ids($a), app(RedirectionRepository::class)->memberAllIpIds($a)->map(fn ($i) => (int) $i)->sort()->values()->all());
        $this->assertSame(1, IpOwner::ofMember($b)->count());
    }
}
