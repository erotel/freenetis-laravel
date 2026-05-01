<?php
namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ForgottenPasswordController extends Controller
{
    public function create()
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);
        return view('auth.forgotten_password');
    }

    /**
     * TTL pro password reset token v minutách. Po této době je token neplatný.
     */
    private const TOKEN_TTL_MIN = 60;

    /**
     * Generic response — vždy vrátit stejnou zprávu, ať uživatel existuje nebo ne.
     * Brání enumeration loginů / emailů / variabilních symbolů přes captive timing.
     */
    private function genericResponse()
    {
        return redirect()->route('forgotten-password')
            ->with('success', 'Pokud existuje účet odpovídající zadanému údaji, zaslali jsme na příslušný e-mail odkaz pro reset hesla. Pokud zpráva nedorazí do 20 minut, kontaktujte prosím podporu.');
    }

    public function store(Request $request)
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);

        $request->validate([
            'login' => 'required|string',
        ]);

        $input = trim($request->login);

        // 1) Try by login
        $user = DB::table('users')->where('login', $input)->first();

        // 2) Try by email via contacts (jen pokud se vejde právě jeden uživatel)
        if (!$user) {
            $contact = DB::table('contacts')
                ->where('type', 20)
                ->where('value', $input)
                ->first();
            if ($contact) {
                $userIds = DB::table('users_contacts')
                    ->where('contact_id', $contact->id)
                    ->pluck('user_id');
                if ($userIds->count() === 1) {
                    $user = DB::table('users')->where('id', $userIds->first())->first();
                }
                // Více shod (sdílený email) → ignoruj, vrátí generic response. Bezpečnější
                // než původní explicitní hláška, která potvrdila existenci emailu v systému.
            }
        }

        // 3) Try by variable symbol
        if (!$user && ctype_digit($input)) {
            $accountId = DB::table('variable_symbols')
                ->where('variable_symbol', $input)
                ->value('account_id');
            if ($accountId) {
                $memberId = DB::table('accounts')->where('id', $accountId)->value('member_id');
                if ($memberId) {
                    $user = DB::table('users')
                        ->where('member_id', $memberId)
                        ->where('type', 1)
                        ->first();
                }
            }
        }

        // Pokud nenajdeme uživatele NEBO uživatel nemá email — vrátíme stejnou zprávu.
        // (Nepošleme nic, ale uživatel to nepozná → enumeration je uzavřená.)
        if (!$user) {
            return $this->genericResponse();
        }

        $email = DB::table('contacts')
            ->join('users_contacts', 'contacts.id', '=', 'users_contacts.contact_id')
            ->where('users_contacts.user_id', $user->id)
            ->where('contacts.type', 20)
            ->value('contacts.value');

        if (!$email) {
            return $this->genericResponse();
        }

        $token = Str::random(40);
        DB::table('users')->where('id', $user->id)->update([
            'password_request'             => $token,
            'password_request_expires_at'  => now()->addMinutes(self::TOKEN_TTL_MIN),
        ]);

        $siteTitle = Setting::get('title', 'FreenetIS');
        $resetUrl  = route('forgotten-password.reset') . '?request=' . $token;
        $fromEmail = Setting::get('email_default_email', 'noreply@freenetis.org');
        $ttlMin    = self::TOKEN_TTL_MIN;

        DB::table('email_queues')->insert([
            'from'    => $fromEmail,
            'to'      => $email,
            'subject' => $siteTitle . ' :: Reset hesla',
            'body'    => "Dobrý den,\n\nPro reset hesla klikněte na tento odkaz (platnost {$ttlMin} minut):\n{$resetUrl}\n\nPokud jste o reset hesla nežádali, ignorujte tento email.\n\n{$siteTitle}",
            'state'   => 0,
        ]);

        return $this->genericResponse();
    }

    /**
     * Najde uživatele podle tokenu, kontroluje platnost (expirace).
     * Vrací null pokud token chybí, neexistuje, nebo expiroval.
     */
    private function findValidTokenUser(?string $token): ?object
    {
        if (!$token) return null;

        $user = DB::table('users')->where('password_request', $token)->first();
        if (!$user) return null;

        // Token bez expirace (legacy záznam, nebo migrovaný DB) → odmítnout.
        // Force re-request, protože nevíme jak je starý.
        if (empty($user->password_request_expires_at)) return null;

        if (\Carbon\Carbon::parse($user->password_request_expires_at)->isPast()) {
            return null;
        }

        return $user;
    }

    public function reset(Request $request)
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);

        $token = $request->query('request');
        $user  = $this->findValidTokenUser($token);
        if (!$user) {
            return redirect()->route('login')
                ->with('error', 'Odkaz pro reset hesla je neplatný nebo vypršel.');
        }

        return view('auth.reset_password', compact('token'));
    }

    public function update(Request $request)
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);

        $minLength = (int) Setting::get('security_password_length', 8);

        $request->validate([
            'token'                 => 'required|string',
            'password'              => "required|string|min:{$minLength}|confirmed",
            'password_confirmation' => 'required',
        ]);

        $user = $this->findValidTokenUser($request->token);
        if (!$user) {
            return redirect()->route('login')
                ->with('error', 'Odkaz pro reset hesla je neplatný nebo vypršel.');
        }

        // Atomicky vynulovat token + jeho expiraci, aby ho nešlo znovu použít (race protection).
        DB::table('users')->where('id', $user->id)->update([
            'password'                    => bcrypt($request->password),
            'password_request'            => null,
            'password_request_expires_at' => null,
        ]);

        // Zneplatnit všechny existující sessiony tohoto uživatele — kdo už byl přihlášen,
        // se musí přihlásit znovu (mitigace situace, kdy útočník měl session a oběť reset).
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return redirect()->route('login')
            ->with('success', 'Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.');
    }
}
