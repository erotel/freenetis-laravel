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
    private const TOKEN_TTL_MIN = 15;

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

        // 2) Try by email via contacts.
        // Email může být v `contacts` ve více řádcích (legacy duplikáty) i jeden řádek
        // může být sdílený mezi víc usery (rodinný email). Vybereme všechny adepty
        // a zúžíme na ty, co mají povolený přístup do FreenetISu (`members.locked = 0`
        // nebo žádný member). Pošleme jen když zbude právě 1 — jinak generic response.
        if (!$user) {
            $contactIds = DB::table('contacts')
                ->where('type', 20)
                ->where('value', $input)
                ->pluck('id');

            if ($contactIds->isNotEmpty()) {
                $userIds = DB::table('users_contacts')
                    ->whereIn('contact_id', $contactIds)
                    ->pluck('user_id')
                    ->unique();

                $activeUserIds = DB::table('users')
                    ->leftJoin('members', 'members.id', '=', 'users.member_id')
                    ->whereIn('users.id', $userIds)
                    ->where(function ($q) {
                        $q->whereNull('members.locked')->orWhere('members.locked', 0);
                    })
                    ->pluck('users.id');

                if ($activeUserIds->count() === 1) {
                    $user = DB::table('users')->where('id', $activeUserIds->first())->first();
                }
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

        // Do e-mailu jde raw token; do DB jen jeho SHA-256 hash (únik DB pak
        // nedá útočníkovi použitelný token). Token je vysoce náhodný (40 znaků),
        // takže sha256 stačí (rychlý deterministický lookup, neprolomitelný).
        $token = Str::random(40);
        DB::table('users')->where('id', $user->id)->update([
            'password_request'             => hash('sha256', $token),
            'password_request_expires_at'  => now()->addMinutes(self::TOKEN_TTL_MIN),
        ]);

        // Audit: žádost o reset hesla (token samotný neukládáme — je citlivý).
        \App\Services\AuditLogger::log('updated', 'users', (int) $user->id, null, [
            'password_reset_requested' => true,
        ]);

        $siteTitle = Setting::get('title', 'FreenetIS');
        $resetUrl  = route('forgotten-password.reset') . '?request=' . $token;
        $fromEmail = Setting::get('email_default_email', 'noreply@freenetis.org');
        $ttlMin    = self::TOKEN_TTL_MIN;

        $urlHtml   = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleHtml = htmlspecialchars($siteTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<p>Dobrý den,</p>'
            . '<p>pro reset hesla klikněte na tento odkaz (platnost ' . $ttlMin . ' minut):</p>'
            . '<p><a href="' . $urlHtml . '">' . $urlHtml . '</a></p>'
            . '<p>Pokud jste o reset hesla nežádali, ignorujte tento email.</p>'
            . '<p>' . $titleHtml . '</p>';

        DB::table('email_queues')->insert([
            'from'    => $fromEmail,
            'to'      => $email,
            'subject' => $siteTitle . ' :: Reset hesla',
            'body'    => $body,
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

        $user = DB::table('users')->where('password_request', hash('sha256', $token))->first();
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

        // Audit: dokončený reset hesla (heslo redigováno na '***'). Bezpečnostní událost.
        \App\Services\AuditLogger::log('updated', 'users', (int) $user->id, null, [
            'password'      => '***',
            'password_reset' => true,
        ]);

        // Zneplatnit všechny existující sessiony tohoto uživatele — kdo už byl přihlášen,
        // se musí přihlásit znovu (mitigace situace, kdy útočník měl session a oběť reset).
        DB::table('sessions')->where('user_id', $user->id)->delete();

        return redirect()->route('login')
            ->with('success', 'Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.');
    }
}
