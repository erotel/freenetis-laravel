<?php

namespace App\Http\Controllers;

use App\Domains\Members\Actions\AddAccountNoteAction;
use App\Domains\Members\Queries\FieldMemberCardQuery;
use App\Domains\Members\Queries\FieldSearchQuery;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Network\Queries\FieldDeviceQuery;
use App\Models\Setting;
use App\Services\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Field Mode — mobile-first podmnožina FreenetIS pro techniky v terénu.
 *
 * Žádný nový auth (používá existující 'web' guard + FreenetisUserProvider),
 * žádná nová role — gate je existující ACL (view_all members). Technici jsou
 * zároveň admini, takže přístup mají. Data v app/Domains/Members (hledání,
 * karta, poznámka) a Network (detail zařízení).
 */
class FieldController extends Controller
{
    public function __construct(private LoginService $loginService) {}

    // ── PWA assety ──────────────────────────────────────────────────────────────
    // Servírujeme přes Laravel (ne ze složky public/field), protože cesta /field/*
    // koliduje s routou /field — fyzická složka by Apache vrátila jako directory
    // listing místo Laravelu. Z resources/field je web přímo nedostupné.

    public function manifest()
    {
        return response()->file(resource_path('field/manifest.json'), [
            'Content-Type'  => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function serviceWorker()
    {
        return response()->file(resource_path('field/sw.js'), [
            'Content-Type'           => 'application/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control'          => 'no-cache',
        ]);
    }

    public function offline()
    {
        return response()->file(resource_path('field/offline.html'), [
            'Content-Type'  => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function icon(int $size)
    {
        abort_unless(in_array($size, [192, 512], true), 404);
        return response()->file(resource_path("field/icon-{$size}.png"), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    // ── Auth ──────────────────────────────────────────────────────────────────

    public function home()
    {
        return Auth::check()
            ? redirect()->route('field.search')
            : redirect()->route('field.login');
    }

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('field.search');
        }
        return view('field.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Stejný ověřovací flow jako /login (sdílená LoginService): rate-limit,
        // trvalý zámek účtu, members.locked i MFA gate. Field login NESMÍ být
        // druhá cesta, která tyhle brány obchází.
        $result = $this->loginService->attempt($credentials, $request, 'field');

        switch ($result['status']) {
            case LoginService::RATE_LIMITED:
                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['login' => __('Příliš mnoho neúspěšných pokusů. Zkuste to znovu za :s s.', ['s' => $result['seconds']])]);

            case LoginService::LOCKED:
                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['login' => __('Účet je dočasně uzamčen kvůli opakovaným neúspěšným pokusům. Zkuste to znovu za :m min.', ['m' => $result['minutes']])]);

            case LoginService::INVALID:
                return back()
                    ->withInput($request->only('login'))
                    ->withErrors(['login' => __('Nesprávné jméno nebo heslo, nebo je účet zablokován.')]);

            case LoginService::MFA_REQUIRED:
                // Po ověření 2. faktoru se má technik vrátit do Field UI, ne na
                // desktop dashboard → nastavíme intended URL pro challenge.
                $this->loginService->beginMfaChallenge($result['user'], $request);
                $request->session()->put('url.intended', route('field.search'));
                return redirect()->route('mfa.challenge');
        }

        $this->loginService->completeLogin($result['user'], $request);
        return redirect()->intended(route('field.search'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('field.login');
    }

    // ── Search ────────────────────────────────────────────────────────────────

    public function search()
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'members'), 403);
        return view('field.search');
    }

    /** Live-search JSON: zařízení nahoře, pak max 20 členů podle relevance. */
    public function searchResults(Request $request, FieldSearchQuery $query): JsonResponse
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'members'), 403);

        return response()->json($query->search(trim($request->get('q', '')), (bool) Setting::get('networks_enabled', 0)));
    }

    // ── Member detail ───────────────────────────────────────────────────────────

    public function member(int $id, FieldMemberCardQuery $card)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'members'), 403);

        $withDevices = $this->aclCheck('view_all', 'Devices_Controller', 'devices') && Setting::get('networks_enabled', 0);
        $data = $card->find($id, (bool) $withDevices);
        abort_if(!$data, 404);

        return view('field.member', $data + [
            'canAddNote' => $this->aclCheck('new_all', 'Members_Controller', 'comment'),
        ]);
    }

    /** Poznámka ke kreditnímu účtu člena (vlákno komentářů účtu). */
    public function addNote(Request $request, int $id, MemberRepository $members, AddAccountNoteAction $addNote)
    {
        abort_unless($this->aclCheck('new_all', 'Members_Controller', 'comment'), 403);

        $request->validate(['text' => 'required|string|max:5000']);
        abort_if(!$members->row($id), 404);

        $commentId = $addNote->handle($id, (string) $request->input('text'), Auth::id(), 'field');
        abort_if($commentId === null, 404, 'Člen nemá kreditní účet.');

        return redirect()->route('field.member', $id)->with('success', 'Poznámka byla přidána.');
    }

    // ── Device detail ─────────────────────────────────────────────────────────

    public function device(int $id, FieldDeviceQuery $devices)
    {
        abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'devices'), 403);

        $data = $devices->find($id);
        abort_if(!$data, 404);

        return view('field.device', $data);
    }
}
