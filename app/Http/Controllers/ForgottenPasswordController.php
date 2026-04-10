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

    public function store(Request $request)
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);

        $request->validate([
            'login' => 'required|string',
        ]);

        $input = trim($request->login);

        // 1) Try by login
        $user = DB::table('users')->where('login', $input)->first();

        // 2) Try by email via contacts
        if (!$user) {
            $contact = DB::table('contacts')
                ->where('type', 20)
                ->where('value', $input)
                ->first();
            if ($contact) {
                $userIds = DB::table('users_contacts')
                    ->where('contact_id', $contact->id)
                    ->pluck('user_id');

                // Multiple members share this email - cannot reset by email
                if ($userIds->count() > 1) {
                    return redirect()->route('forgotten-password')
                        ->withInput()
                        ->with('error', 'Zadaný e-mail je přiřazen více uživatelům. Použijte prosím přihlašovací jméno nebo variabilní symbol.');
                }

                if ($userIds->count() === 1) {
                    $user = DB::table('users')->where('id', $userIds->first())->first();
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

        if (!$user) {
            return redirect()->route('forgotten-password')
                ->withInput()
                ->with('error', 'Přihlašovací jméno, e-mail nebo variabilní symbol nesouhlasí s údaji v informačním systému. Kontaktujte prosím podporu.');
        }

        $token = Str::random(40);
        DB::table('users')->where('id', $user->id)->update([
            'password_request' => $token,
        ]);

        $email = DB::table('contacts')
            ->join('users_contacts', 'contacts.id', '=', 'users_contacts.contact_id')
            ->where('users_contacts.user_id', $user->id)
            ->where('contacts.type', 20)
            ->value('contacts.value');

        if ($email) {
            $siteTitle = Setting::get('title', 'FreenetIS');
            $resetUrl  = route('forgotten-password.reset') . '?request=' . $token;
            $fromEmail = Setting::get('email_default_email', 'noreply@freenetis.org');

            DB::table('email_queues')->insert([
                'from'    => $fromEmail,
                'to'      => $email,
                'subject' => $siteTitle . ' :: Reset hesla',
                'body'    => "Dobrý den,\n\nPro reset hesla klikněte na tento odkaz:\n{$resetUrl}\n\nPokud jste o reset hesla nežádali, ignorujte tento email.\n\n{$siteTitle}",
                'state'   => 0,
            ]);
        }

        $maskedEmail = '';
        if ($email) {
            $parts = explode('@', $email);
            $maskedEmail = substr($parts[0], 0, 1) . str_repeat('*', max(3, strlen($parts[0]) - 1)) . '@' . $parts[1];
        }

        return redirect()->route('forgotten-password')
            ->with('success', 'Žádost byla odeslána na Váš e-mail (' . $maskedEmail . '). Zkontrolujte prosím svou e-mailovou schránku. Pokud zpráva nedorazí do 20 minut, kontaktujte prosím podporu.');
    }

    public function reset(Request $request)
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);

        $token = $request->query('request');
        if (!$token) return redirect()->route('login');

        $user = DB::table('users')->where('password_request', $token)->first();
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

        $user = DB::table('users')->where('password_request', $request->token)->first();
        if (!$user) {
            return redirect()->route('login')
                ->with('error', 'Odkaz pro reset hesla je neplatný nebo vypršel.');
        }

        DB::table('users')->where('id', $user->id)->update([
            'password'         => bcrypt($request->password),
            'password_request' => null,
        ]);

        return redirect()->route('login')
            ->with('success', 'Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.');
    }
}
