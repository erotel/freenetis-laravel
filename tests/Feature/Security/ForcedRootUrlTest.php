<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Absolutní odkazy se skládají z APP_URL, ne z Host hlavičky požadavku —
 * jinak `POST /forgotten-password` s `Host: utocnik` pošle oběti pravý e-mail
 * s odkazem (a tokenem) na cizí doménu.
 */
class ForcedRootUrlTest extends DatabaseTestCase
{
    private const EVIL = 'http://evil.example';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('forgotten_password', 1);
        Setting::set('email_default_email', 'noreply@example.test');
    }

    public function test_odkaz_na_reset_hesla_je_z_app_url(): void
    {
        $login = 'charhost_' . uniqid();
        $m = (int) DB::table('members')->insertGetId(['name' => 'Char Host', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $u = (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => $login, 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'H', 'comment' => '']);
        $c = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => "{$login}@example.invalid"]);
        DB::table('users_contacts')->insert(['user_id' => $u, 'contact_id' => $c]);

        $this->post(self::EVIL . '/forgotten-password', ['login' => $login])->assertRedirect();

        $body = (string) DB::table('email_queues')->where('to', "{$login}@example.invalid")->orderByDesc('id')->value('body');
        $this->assertStringContainsString(rtrim(config('app.url'), '/') . '/forgotten-password/reset?request=', $body);
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function test_stranka_s_cizim_hostem_neodkazuje_na_cizi_domenu(): void
    {
        $html = $this->get(self::EVIL . '/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringContainsString(rtrim(config('app.url'), '/') . '/login', $html);
    }
}
