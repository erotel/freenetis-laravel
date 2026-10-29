<?php

namespace Tests\Feature\Members;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Jméno/příjmení z veřejné registrace jde do RouterOS exportů (komentář leasu)
 * — zakázané jsou i znaky skriptu (" [ ] $ \ a řídicí), běžné firemní znaky
 * (& | – . ,) a diakritika zůstávají povolené.
 */
class RegistrationNameCharsTest extends DatabaseTestCase
{
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('self_registration', 1);
        Setting::set('registration_summary_enabled', 0);
        $this->tag = 'rn' . substr(md5(uniqid('', true)), 0, 8);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);   // registrace má throttle:5,10
    }

    private function data(array $over = []): array
    {
        return $over + [
            'registration_type' => 18, 'name' => 'Jana', 'surname' => 'Nováková', 'birthday' => '1990-05-04',
            'login' => $this->tag, 'password' => \Tests\Support\Fake::secret('silneheslo'), 'password_confirmation' => \Tests\Support\Fake::secret('silneheslo'),
            'email' => "{$this->tag}@example.invalid", 'phone' => '+420777000111',
            'town_id' => (int) DB::table('towns')->value('id'), 'street_id' => (int) DB::table('streets')->value('id'), 'street_number' => '12',
            'organization_identifier' => '', 'vat_organization_identifier' => '', 'comment' => '',
        ];
    }

    public function test_znaky_skriptu_jsou_odmitnute(): void
    {
        foreach (['x" disabled=yes', 'a[/user add]', 'a$b', 'a\\b', "Jan\r\n/system reset"] as $bad) {
            $this->post(route('registration.store'), $this->data(['name' => $bad]))->assertSessionHasErrors('name');
            $this->post(route('registration.store'), $this->data(['surname' => $bad]))->assertSessionHasErrors('surname');
        }
        $this->assertFalse(DB::table('users')->where('login', $this->tag)->exists());
    }

    public function test_bezne_firemni_znaky_projdou(): void
    {
        $this->post(route('registration.store'), $this->data([
            'registration_type' => 3, 'name' => 'Charvát & syn – J|M, s.r.o.', 'surname' => '', 'birthday' => now()->subYear()->toDateString(),
        ]))->assertSessionDoesntHaveErrors(['name', 'surname']);
    }
}
