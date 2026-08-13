<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * M2M autentizace web-interface endpointů (NIS2/ZoKB) — token vedle IP-allowlistu.
 * Fáze 1 permisivní: token NEBO důvěryhodná IP. Vynucení (fáze 2): jen token
 * (localhost výjimka pro lokální konzumenty).
 */
class WebInterfaceTokenTest extends DatabaseTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = 'testtok_' . uniqid();
        Setting::set('web_interface_api_token', $this->token);
        Setting::set('address_ranges', '10.0.0.0/8'); // nezahrnuje 127.0.0.1 ani 1.2.3.4
        Setting::set('web_interface_require_token', 0);
    }

    private function hit(string $ip, string $query = ''): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('/web-interface/qos-json' . $query);
    }

    public function test_platny_token_projde_i_z_neduveryhodne_ip(): void
    {
        $this->hit('1.2.3.4', '?token=' . $this->token)->assertOk();
    }

    public function test_permisivni_bez_tokenu_z_neduveryhodne_ip_403(): void
    {
        $this->hit('1.2.3.4')->assertForbidden();
    }

    public function test_permisivni_bez_tokenu_z_duveryhodne_ip_projde(): void
    {
        $this->hit('10.133.1.1')->assertOk(); // v address_ranges 10.0.0.0/8
    }

    public function test_vynuceni_bez_tokenu_403(): void
    {
        Setting::set('web_interface_require_token', 1);
        // I z důvěryhodné IP: při vynucení samotná IP nestačí.
        $this->hit('10.133.1.1')->assertForbidden();
    }

    public function test_vynuceni_s_tokenem_projde(): void
    {
        Setting::set('web_interface_require_token', 1);
        $this->hit('1.2.3.4', '?token=' . $this->token)->assertOk();
    }

    public function test_vynuceni_localhost_vyjimka(): void
    {
        Setting::set('web_interface_require_token', 1);
        $this->hit('127.0.0.1')->assertOk();
    }
}
