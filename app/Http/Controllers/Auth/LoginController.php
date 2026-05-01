<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Remember-me záměrně NEpodporujeme — User model nemá remember_token sloupec,
        // tj. cookie by byla vázaná na NULL token (forgeable, nerevokovatelná). Pokud by
        // remember-me bylo potřeba, doplň migraci pro users.remember_token a opravu
        // get/set/getRememberTokenName v App\Models\User.
        if (!Auth::attempt($credentials)) {
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __('Nesprávné přihlašovací jméno nebo heslo, nebo je účet zablokován.')]);
        }

        $request->session()->regenerate();

        // Write to FreenetIS login_logs table
        DB::table('login_logs')->insert([
            'user_id'    => Auth::id(),
            'time'       => now(),
            'IP_address' => $request->ip(),
        ]);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
