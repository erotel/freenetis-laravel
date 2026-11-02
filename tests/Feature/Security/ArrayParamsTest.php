<?php

namespace Tests\Feature\Security;

use Tests\DatabaseTestCase;

/** Tokeny / login / kódy poslané jako pole (`?t[]=x`) → 400, ne 500. */
class ArrayParamsTest extends DatabaseTestCase
{
    public function test_pole_misto_textu_je_400(): void
    {
        foreach (['/sign/contract?t[]=x', '/sign/download?t[]=x', '/sign/addon?t[]=x', '/forgotten-password/reset?request[]=x',
                  '/web-interface/qos-json?token[]=x'] as $url) {
            $this->get($url)->assertStatus(400);
        }
        $this->postJson(route('sign.info'), ['t' => ['x']])->assertStatus(400)->assertExactJson(['error' => 'Neplatný požadavek.']);
        $this->post('/login', ['login' => ['x'], 'password' => 'y'])->assertStatus(400);
    }

    public function test_text_projde_dal(): void
    {
        $this->get('/sign/contract?t=x')->assertOk();
        $this->postJson(route('sign.info'), ['t' => 'x'])->assertStatus(401);
        $this->post('/login', ['login' => 'neexistuje', 'password' => 'y'])->assertRedirect();
    }
}
