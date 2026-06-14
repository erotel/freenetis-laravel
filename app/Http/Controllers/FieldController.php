<?php

namespace App\Http\Controllers;

use App\Helpers\MemberType;
use App\Models\AccountAttribute;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Field Mode — mobile-first podmnožina FreenetIS pro techniky v terénu.
 *
 * Žádný nový auth (používá existující 'web' guard + FreenetisUserProvider),
 * žádná nová role — gate je existující ACL (view_all members). Technici jsou
 * zároveň admini, takže přístup mají.
 */
class FieldController extends Controller
{
    /** Per-username throttle pro field login (stejně jako Auth\LoginController). */
    private const LOGIN_MAX_ATTEMPTS = 10;
    private const LOGIN_DECAY_SECONDS = 300;

    /** Member status tečka. */
    private function memberStatus(?bool $locked, ?float $balance): string
    {
        if ($locked) {
            return 'suspended';
        }
        if ($balance !== null && $balance < 0) {
            return 'overdue';
        }
        return 'ok';
    }

    /** Sestaví čitelnou adresu z address_point řádku (street/number/town). */
    private function formatAddress(?string $street, ?string $number, ?string $town): ?string
    {
        $line = trim(trim((string) $street) . ' ' . trim((string) $number));
        $parts = array_filter([$line !== '' ? $line : null, $town ?: null]);
        return $parts ? implode(', ', $parts) : null;
    }

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

        $loginKey = 'field_login_user:' . sha1(strtolower(trim((string) $credentials['login'])));
        if (RateLimiter::tooManyAttempts($loginKey, self::LOGIN_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($loginKey);
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __('Příliš mnoho neúspěšných pokusů. Zkuste to znovu za :s s.', ['s' => $seconds])]);
        }

        if (!Auth::attempt($credentials)) {
            RateLimiter::hit($loginKey, self::LOGIN_DECAY_SECONDS);
            return back()
                ->withInput($request->only('login'))
                ->withErrors(['login' => __('Nesprávné jméno nebo heslo, nebo je účet zablokován.')]);
        }

        RateLimiter::clear($loginKey);
        $request->session()->regenerate();

        DB::table('login_logs')->insert([
            'user_id'    => Auth::id(),
            'time'       => now(),
            'IP_address' => $request->ip(),
        ]);

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

    /**
     * Live-search JSON endpoint. Vrací max 20 členů seřazených podle relevance.
     *
     * Pozn.: "zaplaceno do" (Account::getExpirationDate) je drahá iterativní
     * kalkulace — v seznamu ji NEpočítáme (běželo by 20× při každém stisku).
     * Status v seznamu se odvozuje z locked + znaménka salda; přesné datum
     * expirace je na detailu člena.
     */
    public function searchResults(Request $request): JsonResponse
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'members'), 403);

        $query = trim($request->get('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $like     = '%' . $query . '%';
        $networks = (bool) Setting::get('networks_enabled', 0);
        // Tokenizace pro víceslovné dotazy ("zatloukal martin", "krumsín 167").
        // Každý token musí matchnout (AND) — pořadí slov je jedno.
        $tokens = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY);
        $multi  = count($tokens) > 1;

        // Posbírej member_id z jednotlivých cílených dotazů. Sub-limity drží
        // pool malý a brání kartézské explozi z více JOINů naráz.
        $ids = collect();

        // Jméno + ID + IČO. Jméno je v jednom poli ("Martin Zatloukal"), takže
        // víceslovný dotaz matchujeme jako AND tokenů (libovolné pořadí).
        $ids = $ids->merge(
            DB::table('members as m')
                ->where(function ($q) use ($like, $query, $tokens, $multi) {
                    $q->where('m.name', 'LIKE', $like)
                      ->orWhere('m.id', '=', ctype_digit($query) ? (int) $query : -1)
                      ->orWhere('m.organization_identifier', 'LIKE', $like);
                    if ($multi) {
                        $q->orWhere(function ($q) use ($tokens) {
                            foreach ($tokens as $t) {
                                $q->where('m.name', 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->limit(40)->pluck('m.id')
        );

        // Variabilní symbol → account → member
        $ids = $ids->merge(
            DB::table('variable_symbols as vs')
                ->join('accounts as a', 'a.id', '=', 'vs.account_id')
                ->where('vs.variable_symbol', 'LIKE', $like)
                ->limit(40)->pluck('a.member_id')
        );

        // Adresa (ulice / číslo / obec). Víceslovný dotaz ("krumsín 167",
        // "manharda 19 prostějov") matchujeme přes composite — každý token
        // musí být někde v "ulice číslo obec".
        $ids = $ids->merge(
            DB::table('members as m')
                ->join('address_points as ap', 'ap.id', '=', 'm.address_point_id')
                ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                ->where(function ($q) use ($like, $tokens, $multi) {
                    $q->where('ap.street_number', 'LIKE', $like)
                      ->orWhere('s.street', 'LIKE', $like)
                      ->orWhere('t.town', 'LIKE', $like);
                    if ($multi) {
                        $composite = "CONCAT_WS(' ', IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(t.town,''))";
                        $q->orWhere(function ($q) use ($tokens, $composite) {
                            foreach ($tokens as $t) {
                                $q->where(DB::raw($composite), 'LIKE', '%' . $t . '%');
                            }
                        });
                    }
                })
                ->limit(40)->pluck('m.id')
        );

        // Telefon + email (contacts přes users)
        $ids = $ids->merge(
            DB::table('contacts as c')
                ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                ->join('users as u', 'u.id', '=', 'uc.user_id')
                ->whereIn('c.type', [Contact::TYPE_EMAIL, Contact::TYPE_PHONE])
                ->where('c.value', 'LIKE', $like)
                ->whereNotNull('u.member_id')
                ->limit(40)->pluck('u.member_id')
        );

        if ($networks) {
            // IP adresa → člen přes přímý FK (jen ~8 % IP ho má vyplněné,
            // typicky IP alokované přímo členovi).
            $ids = $ids->merge(
                DB::table('ip_addresses')
                    ->where('ip_address', 'LIKE', $like)
                    ->whereNotNull('member_id')
                    ->limit(40)->pluck('member_id')
            );

            // IP adresa → člen přes zařízení (ip → iface → device → user → member).
            // Většina IP (zařízení) má ip_addresses.member_id NULL a vlastníka
            // jen přes tento řetězec — bez něj se podle IP zařízení nenajde.
            $ids = $ids->merge(
                DB::table('ip_addresses as ip')
                    ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                    ->join('devices as d', 'd.id', '=', 'i.device_id')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('ip.ip_address', 'LIKE', $like)
                    ->whereNotNull('u.member_id')
                    ->limit(40)->pluck('u.member_id')
            );

            // Zařízení podle jména → user → member
            $ids = $ids->merge(
                DB::table('devices as d')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('d.name', 'LIKE', $like)
                    ->whereNotNull('u.member_id')
                    ->limit(40)->pluck('u.member_id')
            );
        }

        $ids = $ids->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return response()->json([]);
        }

        // Načti member info + adresu + kreditní účet (saldo) jedním dotazem.
        $rows = DB::table('members as m')
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('accounts as acc', function ($j) {
                $j->on('acc.member_id', '=', 'm.id')
                  ->where('acc.account_attribute_id', '=', AccountAttribute::CREDIT_ATTRIBUTE_ID);
            })
            ->whereIn('m.id', $ids)
            ->select(
                'm.id', 'm.name', 'm.type', 'm.locked',
                's.street', 'ap.street_number', 't.town',
                'acc.balance'
            )
            ->get();

        // Jeden VS na člena (nejnižší) pro zobrazení.
        $vsMap = DB::table('variable_symbols as vs')
            ->join('accounts as a', 'a.id', '=', 'vs.account_id')
            ->whereIn('a.member_id', $ids)
            ->groupBy('a.member_id')
            ->select('a.member_id', DB::raw('MIN(vs.variable_symbol) as vs'))
            ->pluck('vs', 'a.member_id');

        $q = mb_strtolower($query);
        $results = $rows->map(function ($r) use ($vsMap, $q) {
            $balance = $r->balance !== null ? (float) $r->balance : null;
            $vs      = $vsMap[$r->id] ?? null;

            // Lehká relevance: exact VS / exact ID / prefix jména = nahoru.
            $score = 0;
            if ($vs !== null && (string) $vs === $q)            $score += 100;
            if ((string) $r->id === $q)                          $score += 100;
            if (str_starts_with(mb_strtolower((string) $r->name), $q)) $score += 50;

            return [
                'kind'       => 'member',
                'id'         => (int) $r->id,
                'name'       => $r->name,
                'vs'         => $vs,
                'type_label' => MemberType::label((int) $r->type),
                'address'    => $this->formatAddress($r->street, $r->street_number, $r->town),
                'status'     => $this->memberStatus((bool) $r->locked, $balance),
                'balance'    => $balance,
                '_score'     => $score,
            ];
        })
        ->sortByDesc('_score')
        ->take(20)
        ->map(function ($row) { unset($row['_score']); return $row; })
        ->values();

        // Zařízení matchnutá přímo dotazem (IP v4/v6 nebo jméno) — přidáme jako
        // samostatné výsledky s odkazem rovnou na detail zařízení. Bez toho by
        // technik u členů s mnoha zařízeními (např. PVfree) musel IP hledat znovu.
        $deviceResults = $this->matchingDevices($query, $like, $networks);

        // Zařízení nahoru (jsou specifičtější než vlastník), pak členové.
        return response()->json($deviceResults->concat($results)->values());
    }

    /**
     * Najde zařízení odpovídající dotazu podle jména nebo IP (v4/v6) a vrátí je
     * jako výsledky s odkazem na detail zařízení. Max 10.
     */
    private function matchingDevices(string $query, string $like, bool $networks)
    {
        if (!$networks) {
            return collect();
        }

        $devices = DB::table('devices as d')
            ->join('users as u', 'u.id', '=', 'd.user_id')
            ->leftJoin('members as m', 'm.id', '=', 'u.member_id')
            ->leftJoin('ifaces as i', 'i.device_id', '=', 'd.id')
            ->leftJoin('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->leftJoin('ip6_addresses as ip6', 'ip6.iface_id', '=', 'i.id')
            ->where(function ($q) use ($like) {
                $q->where('d.name', 'LIKE', $like)
                  ->orWhere('ip.ip_address', 'LIKE', $like)
                  ->orWhere('ip6.ip_address', 'LIKE', $like);
            })
            ->select('d.id', 'd.name', 'm.name as member_name')
            ->distinct()
            ->limit(10)
            ->get();

        if ($devices->isEmpty()) {
            return collect();
        }

        // IP (v4+v6) pro nalezená zařízení — ať můžeme ukázat tu, která matchla.
        $devIds = $devices->pluck('id');
        $ipMap  = [];
        $rowsV4 = DB::table('ip_addresses as ip')->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->whereIn('i.device_id', $devIds)->select('i.device_id', 'ip.ip_address')->get();
        $rowsV6 = DB::table('ip6_addresses as ip')->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
            ->whereIn('i.device_id', $devIds)->select('i.device_id', 'ip.ip_address')->get();
        foreach ($rowsV4->concat($rowsV6) as $r) {
            $ipMap[$r->device_id][] = $r->ip_address;
        }

        return $devices->map(function ($d) use ($ipMap, $query) {
            $ips = collect($ipMap[$d->id] ?? []);
            // Preferuj IP, která obsahuje dotaz (hledal-li podle IP), jinak první.
            $showIp = $ips->first(fn($x) => stripos($x, $query) !== false) ?? $ips->first();
            $detail = collect([$showIp, $d->member_name])->filter()->implode(' · ');

            return [
                'kind'   => 'device',
                'id'     => (int) $d->id,
                'name'   => $d->name,
                'detail' => $detail,
            ];
        })->values();
    }

    // ── Member detail ───────────────────────────────────────────────────────────

    public function member(int $id)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'members'), 403);

        $member = Member::with([
            'users',
            'accounts.variableSymbols',
            'addressPoint.town',
            'addressPoint.street',
        ])->find($id);
        abort_if(!$member, 404);

        $variableSymbols = $member->accounts
            ->flatMap(fn($a) => $a->variableSymbols)
            ->pluck('variable_symbol')
            ->unique()
            ->values();

        $creditAccount = $member->accounts
            ->first(fn($a) => $a->account_attribute_id === AccountAttribute::CREDIT_ATTRIBUTE_ID);

        $balance        = $creditAccount ? (float) $creditAccount->balance : null;
        $expirationDate = $creditAccount?->getExpirationDate();

        $mainUser = $member->users->firstWhere('type', User::MAIN_USER);
        $contacts = $mainUser
            ? $mainUser->contacts()->with('enumType')->get()
            : collect();

        $phones = $contacts->where('type', Contact::TYPE_PHONE)->pluck('value')->filter()->values();
        $emails = $contacts->where('type', Contact::TYPE_EMAIL)->pluck('value')->filter()->values();

        // Aktivní pravidelný poplatek (member-specific, pak default dle typu).
        $activeMemberFee = MemberFee::where('member_id', $member->id)->active()->with('fee')->first();
        if (!$activeMemberFee) {
            $defaultFeeId = (int) Setting::get('default_fee_member_type_' . $member->type, 0);
            if ($defaultFeeId) {
                $defaultFee = \App\Models\Fee::find($defaultFeeId);
                if ($defaultFee) {
                    $activeMemberFee = new MemberFee();
                    $activeMemberFee->setRelation('fee', $defaultFee);
                }
            }
        }

        // Zařízení člena: přes jeho uživatele (devices.user_id → users.member_id).
        $devices = collect();
        if ($this->aclCheck('view_all', 'Devices_Controller', 'devices') && Setting::get('networks_enabled', 0)) {
            $userIds = $member->users->pluck('id');
            if ($userIds->isNotEmpty()) {
                $devices = Device::whereIn('user_id', $userIds)
                    ->with('ifaces.ipAddresses')
                    ->orderBy('name')
                    ->get();
            }
        }

        $address = $this->formatAddress(
            $member->addressPoint?->street?->street,
            $member->addressPoint?->street_number,
            $member->addressPoint?->town?->town
        );

        return view('field.member', [
            'member'          => $member,
            'status'          => $this->memberStatus((bool) $member->locked, $balance),
            'variableSymbols' => $variableSymbols,
            'balance'         => $balance,
            'expirationDate'  => $expirationDate,
            'activeMemberFee' => $activeMemberFee,
            'phones'          => $phones,
            'emails'          => $emails,
            'address'         => $address,
            'devices'         => $devices,
            'canAddNote'      => $this->aclCheck('new_all', 'Members_Controller', 'comment'),
        ]);
    }

    /**
     * Přidá poznámku jako komentář k vláknu kreditního účtu člena.
     * Vlákno se vytvoří líně (stejně jako CommentController::addThread).
     */
    public function addNote(Request $request, int $id)
    {
        abort_unless($this->aclCheck('new_all', 'Members_Controller', 'comment'), 403);

        $request->validate(['text' => 'required|string|max:5000']);

        $member = Member::with('accounts')->find($id);
        abort_if(!$member, 404);

        $creditAccount = $member->accounts
            ->first(fn($a) => $a->account_attribute_id === AccountAttribute::CREDIT_ATTRIBUTE_ID);
        abort_if(!$creditAccount, 404, 'Člen nemá kreditní účet.');

        $threadId = $creditAccount->comments_thread_id;
        if (!$threadId) {
            $threadId = DB::table('comments_threads')->insertGetId(['type' => 'account']);
            DB::table('accounts')->where('id', $creditAccount->id)->update(['comments_thread_id' => $threadId]);
        }

        DB::table('comments')->insert([
            'comments_thread_id' => $threadId,
            'user_id'            => Auth::id(),
            'text'               => trim($request->input('text')),
            'datetime'           => now(),
        ]);

        return redirect()->route('field.member', $member->id)->with('success', 'Poznámka byla přidána.');
    }

    // ── Device detail ─────────────────────────────────────────────────────────

    public function device(int $id)
    {
        abort_unless($this->aclCheck('view_all', 'Devices_Controller', 'devices'), 403);

        $device = Device::with(['ifaces.ipAddresses', 'ifaces.ip6Addresses', 'user.member', 'enumType'])->find($id);
        abort_if(!$device, 404);

        // Posbírej IPv4 i IPv6 napříč rozhraními.
        $ips = $device->ifaces
            ->flatMap(fn($i) => $i->ipAddresses)
            ->pluck('ip_address')->filter()->unique()->values();

        $ip6s = $device->ifaces
            ->flatMap(fn($i) => $i->ip6Addresses)
            ->pluck('ip_address')->filter()->unique()->values();

        return view('field.device', [
            'device' => $device,
            'ips'    => $ips,
            'ip6s'   => $ip6s,
            'member' => $device->user?->member,
        ]);
    }
}
