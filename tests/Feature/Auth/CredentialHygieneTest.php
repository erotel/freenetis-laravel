<?php

namespace Tests\Feature\Auth;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

/**
 * Hygiena přihlašovacích údajů (NIS2/ZoKB): reset-token se ukládá jako hash
 * (plaintextový starý formát neprojde) a application_password se generuje
 * kryptograficky silně.
 */
class CredentialHygieneTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('forgotten_password', 1);

        $memberId = (int) DB::table('members')
            ->where('locked', 0)->where('type', 2)->where('leaving_date', '9999-12-31')
            ->value('id');

        $this->userId = (int) DB::table('users')->insertGetId([
            'member_id'            => $memberId,
            'login'               => 'cred_' . uniqid(),
            'password'            => Hash::make('stare-' . uniqid()),
            'type'                => 1,
            'application_password'=> 'xxxxxxxx',
            'settings'            => '',
            'name'                => 'C', 'surname' => 'H', 'comment' => '',
        ]);
    }

    public function test_reset_ulozi_hash_a_hashovany_token_projde(): void
    {
        $raw = Str::random(40);
        DB::table('users')->where('id', $this->userId)->update([
            'password_request'            => hash('sha256', $raw),
            'password_request_expires_at' => now()->addMinutes(30),
        ]);

        // V DB je hash, ne raw token.
        $stored = DB::table('users')->where('id', $this->userId)->value('password_request');
        $this->assertNotSame($raw, $stored);
        $this->assertSame(hash('sha256', $raw), $stored);

        $new = 'NoveHeslo123';
        $r = $this->post('/forgotten-password/reset', [
            'token' => $raw, 'password' => $new, 'password_confirmation' => $new,
        ]);
        $r->assertRedirect(route('login'));

        $u = DB::table('users')->where('id', $this->userId)->first();
        $this->assertNull($u->password_request);                 // token spotřebován
        $this->assertTrue(Hash::check($new, $u->password));       // heslo změněno
    }

    public function test_stary_plaintext_token_neprojde(): void
    {
        // Starý formát: token uložený plaintextem. Nový lookup hashuje vstup,
        // takže se raw plaintext v DB nedohledá → reset selže.
        $raw = Str::random(40);
        DB::table('users')->where('id', $this->userId)->update([
            'password_request'            => $raw, // plaintext (legacy)
            'password_request_expires_at' => now()->addMinutes(30),
        ]);

        $this->post('/forgotten-password/reset', [
            'token' => $raw, 'password' => 'NoveHeslo123', 'password_confirmation' => 'NoveHeslo123',
        ]);

        $u = DB::table('users')->where('id', $this->userId)->first();
        $this->assertSame($raw, $u->password_request); // nezměněno → nezmatchoval
    }

    public function test_application_password_je_silne(): void
    {
        $a = User::generateApplicationPassword();
        $b = User::generateApplicationPassword();

        $this->assertSame(12, strlen($a));
        $this->assertNotSame($a, $b);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $a);
    }

    public function test_application_password_je_v_db_sifrovane(): void
    {
        // Insert-path (controllery): DB::table + Crypt::encryptString.
        $enc = \Illuminate\Support\Facades\Crypt::encryptString(User::generateApplicationPassword());
        DB::table('users')->where('id', $this->userId)->update(['application_password' => $enc]);

        $raw = DB::table('users')->where('id', $this->userId)->value('application_password');
        $this->assertStringStartsWith('eyJ', $raw);                 // šifrovaný blob v DB
        $this->assertSame(12, strlen(User::find($this->userId)->application_password)); // model dešifruje

        // Eloquent mutator taky šifruje.
        $u = User::find($this->userId);
        $u->application_password = 'RUCNE-HESLO';
        $u->save();
        $rawAfter = DB::table('users')->where('id', $this->userId)->value('application_password');
        $this->assertNotSame('RUCNE-HESLO', $rawAfter);
        $this->assertSame('RUCNE-HESLO', User::find($this->userId)->application_password);
    }
}
