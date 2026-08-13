<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Services\LoginLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Zámek účtu po N neúspěšných přihlášeních (NIS2/ZoKB): po prahu se účet
 * zamkne a odmítne i správné heslo; úspěch počítadlo vynuluje; admin odemkne.
 */
class LoginLockTest extends DatabaseTestCase
{
    private int $userId;
    private string $login;
    private string $pass;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('login_lockout_threshold', 3);
        Setting::set('login_lockout_minutes', 15);

        $this->pass  = 'Heslo-' . uniqid();
        $this->login = 'locktest_' . uniqid();

        $memberId = (int) DB::table('members')
            ->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')
            ->value('id');

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $memberId,
            'login'               => $this->login,
            'password'            => Hash::make($this->pass),
            'type'                => 1,
            'application_password'=> 'xxxxxxxx',
            'settings'            => '',
            'name'                => 'Lock', 'surname' => 'Test', 'comment' => '',
        ]);
    }

    private function failLogin(): void
    {
        $this->post('/login', ['login' => $this->login, 'password' => 'spatne-heslo']);
    }

    private function lock(): LoginLockService
    {
        return app(LoginLockService::class);
    }

    public function test_zamek_po_prahu_odmitne_i_spravne_heslo(): void
    {
        $this->failLogin();
        $this->failLogin();
        $this->failLogin(); // 3. selhání = práh → zámek

        $this->assertTrue($this->lock()->isLocked($this->userId));

        // I se správným heslem se během zámku nepřihlásí.
        $r = $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_uspech_vynuluje_pocitadlo(): void
    {
        $this->failLogin();
        $this->failLogin(); // 2 < práh 3
        $this->assertSame(2, $this->lock()->status($this->userId)['failed_count']);

        $r = $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertRedirect(route('dashboard'));
        $this->assertSame(0, $this->lock()->status($this->userId)['failed_count']);
    }

    public function test_admin_odemkne(): void
    {
        $this->failLogin();
        $this->failLogin();
        $this->failLogin();
        $this->assertTrue($this->lock()->isLocked($this->userId));

        $this->lock()->unlock($this->userId);
        $this->assertFalse($this->lock()->isLocked($this->userId));

        $r = $this->post('/login', ['login' => $this->login, 'password' => $this->pass]);
        $r->assertRedirect(route('dashboard'));
    }
}
