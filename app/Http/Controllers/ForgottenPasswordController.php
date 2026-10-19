<?php
namespace App\Http\Controllers;

use App\Domains\Members\Actions\RequestPasswordResetAction;
use App\Domains\Members\Actions\ResetPasswordAction;
use App\Models\Setting;
use Illuminate\Http\Request;

/** Zapomenuté heslo (zapíná Setting forgotten_password); logika v doméně Members. */
class ForgottenPasswordController extends Controller
{
    private function ensureEnabled(): void
    {
        if (!Setting::get('forgotten_password', 0)) abort(404);
    }

    /** Vždy stejná odpověď, ať účet existuje nebo ne (bez enumerace loginů / e-mailů / VS). */
    private function genericResponse()
    {
        return redirect()->route('forgotten-password')
            ->with('success', 'Pokud existuje účet odpovídající zadanému údaji, zaslali jsme na příslušný e-mail odkaz pro reset hesla. Pokud zpráva nedorazí do 20 minut, kontaktujte prosím podporu.');
    }

    private function invalidLink()
    {
        return redirect()->route('login')->with('error', 'Odkaz pro reset hesla je neplatný nebo vypršel.');
    }

    public function create()
    {
        $this->ensureEnabled();
        return view('auth.forgotten_password');
    }

    public function store(Request $request, RequestPasswordResetAction $requestReset)
    {
        $this->ensureEnabled();
        $request->validate(['login' => 'required|string']);

        $requestReset->handle((string) $request->login);
        return $this->genericResponse();
    }

    public function reset(Request $request, ResetPasswordAction $reset)
    {
        $this->ensureEnabled();

        $token = $request->query('request');
        if (!$reset->validUser($token)) {
            return $this->invalidLink();
        }
        return view('auth.reset_password', compact('token'));
    }

    public function update(Request $request, ResetPasswordAction $reset)
    {
        $this->ensureEnabled();

        $minLength = (int) Setting::get('security_password_length', 8);
        $request->validate([
            'token'                 => 'required|string',
            'password'              => "required|string|min:{$minLength}|confirmed",
            'password_confirmation' => 'required',
        ]);

        if (!$reset->handle((string) $request->token, (string) $request->password)) {
            return $this->invalidLink();
        }
        return redirect()->route('login')->with('success', 'Heslo bylo úspěšně změněno. Nyní se můžete přihlásit.');
    }
}
