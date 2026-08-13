<?php

namespace App\Http\Controllers;

use App\Helpers\MemberType;
use App\Models\AccountAttribute;
use App\Models\AddressPoint;
use App\Http\Filters\MemberFilter;
use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\IpAddress;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Setting;
use App\Models\Street;
use App\Models\Town;
use App\Services\ContractService;
use App\Services\RegularFeeResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'members';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    /**
     * Filtrovaný seznam pro dropdown na index stránce — jen typy, se kterými
     * admin reálně pracuje. Schované jsou Žadatel/Čestný/Sympatizant/Nečlen/
     * Fee-free, protože v této instalaci nemají reálné využití (stovky
     * záznamů to v UI jen rozptylovala).
     */
    private static function dropdownMemberTypes(): array
    {
        $labels = MemberType::labels();
        $order = [
            MemberType::CUSTOMER,           // 2  Zákazník
            MemberType::REGULAR,            // 90 Řádný člen
            MemberType::FORMER_CUSTOMER,    // 16 Bývalý zákazník
            MemberType::FORMER,             // 15 Bývalý člen
            MemberType::PENDING_MEMBER,     // 17 Čekající člen
            MemberType::PENDING_CUSTOMER,   // 18 Čekající zákazník
        ];
        $out = [];
        foreach ($order as $id) {
            $out[$id] = $labels[$id];
        }
        return $out;
    }

    public function index(Request $request)
    {
        $allowedSorts = ['id', 'name', 'type', 'entrance_date', 'registration'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : 'id';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, [50, 100, 150, 200, 250, 300, 350, 400, 450, 500], true)) {
            $perPage = 50;
        }

        $search        = trim((string) $request->query('search', ''));
        $currentLocked = $request->query('locked', 'all');

        // types — čárkou oddělený seznam typů (např. "2,16,18") nebo 'all'
        $typesParam = $request->query('types', 'all');
        $currentTypes = 'all';
        $typesArray   = [];
        if ($typesParam !== 'all' && $typesParam !== '') {
            $typesArray = array_filter(
                array_map('intval', explode(',', $typesParam)),
                fn($t) => $t > 0
            );
            if (!empty($typesArray)) {
                $currentTypes = implode(',', $typesArray);
            }
        }

        $query = Member::query()
            ->select([
                'members.id',
                'members.name',
                'members.type',
                'members.registration',
                'members.entrance_date',
                'members.leaving_date',
                'members.locked',
                'members.address_point_id',
                'members.tv_active',
                'members.tv_valid_until',
                'members.tv_synced_at',
                'members.payment_blocked',
                'members.payment_blocked_since',
                'members.pending_termination',
                DB::raw('(SELECT a.balance FROM accounts a WHERE a.member_id = members.id AND a.account_attribute_id = 221100 LIMIT 1) AS credit_balance'),
                DB::raw('(SELECT msg.type FROM messages_ip_addresses mia JOIN ip_addresses ip ON ip.id = mia.ip_address_id JOIN messages msg ON msg.id = mia.message_id WHERE ip.member_id = members.id LIMIT 1) AS redirect_type'),
                DB::raw("(SELECT CASE WHEN mw.permanent = 1 OR mw.until = '9999-12-31' THEN 'permanent' ELSE 'temporary' END FROM members_whitelists mw WHERE mw.member_id = members.id AND mw.since <= CURDATE() AND mw.until >= CURDATE() LIMIT 1) AS whitelist_type"),
            ])
            ->with(['addressPoint.town', 'addressPoint.street']);

        if ($search !== '') {
            // Stejná pole jako SearchController (jméno, IČO/DIČ, adresa, VS, multi-token jméno).
            // Zajišťuje, že odkaz „Otevřít všechny v seznamu" z /search dojde ke stejné množině.
            $like   = '%' . $search . '%';
            $tokens = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
            $multi  = count($tokens) > 1;

            $query->where(function ($q) use ($search, $like, $tokens, $multi) {
                $q->where('members.name', 'like', $like)
                  ->orWhere('members.organization_identifier', 'like', $like)
                  ->orWhere('members.vat_organization_identifier', 'like', $like);

                if (is_numeric($search)) {
                    $q->orWhere('members.id', (int) $search);
                }

                // VS přes accounts/variable_symbols
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('variable_symbols as vs')
                        ->join('accounts as a', 'a.id', '=', 'vs.account_id')
                        ->whereColumn('a.member_id', 'members.id')
                        ->where('vs.variable_symbol', 'like', $like);
                });

                // Adresa (obec, ulice, č.p.) přes address_points
                $q->orWhereExists(function ($sub) use ($like) {
                    $sub->from('address_points as ap')
                        ->leftJoin('towns as t', 't.id', '=', 'ap.town_id')
                        ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                        ->whereColumn('ap.id', 'members.address_point_id')
                        ->where(function ($qq) use ($like) {
                            $qq->where('t.town', 'like', $like)
                               ->orWhere('s.street', 'like', $like)
                               ->orWhere('ap.street_number', 'like', $like);
                        });
                });

                if ($multi) {
                    // Composite přes podselect, aby každý token matchnul v aspoň jednom
                    // z polí: jméno člena, ulice, č.p., obec. Mirror chování SearchController.
                    $q->orWhere(function ($qq) use ($tokens) {
                        foreach ($tokens as $t) {
                            $qq->whereExists(function ($sub) use ($t) {
                                $sub->from('address_points as ap')
                                    ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
                                    ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
                                    ->whereColumn('ap.id', 'members.address_point_id')
                                    ->whereRaw(
                                        "CONCAT_WS(' ', members.name, IFNULL(s.street,''), IFNULL(ap.street_number,''), IFNULL(tn.town,'')) LIKE ?",
                                        ['%' . $t . '%']
                                    );
                            });
                        }
                    });
                }
            });
        }
        if (!empty($typesArray)) {
            $query->whereIn('members.type', $typesArray);
        }
        if ($currentLocked !== 'all') {
            $query->where('members.locked', (int) $currentLocked);
        }

        $advancedFilters = $request->input('filters', []);
        if (!empty($advancedFilters)) {
            MemberFilter::apply($query, $advancedFilters);
        }

        $members = $query->orderBy('members.' . $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        $tvEnabled = (bool) \App\Models\Setting::get('sledovanitv_enabled', 0);

        return view('members.index', [
            'members'         => $members,
            'sort'            => $sort,
            'dir'             => $dir,
            'perPage'         => $perPage,
            'search'          => $search,
            'memberTypes'     => self::dropdownMemberTypes(),
            'currentTypes'    => $currentTypes,
            'currentLocked'   => $currentLocked,
            'filterFields'    => MemberFilter::fields($tvEnabled),
            'currentFilters'  => $advancedFilters,
            'canNew'          => $this->can('new_all'),
            'canEdit'         => $this->can('edit_all'),
            'canDelete'       => $this->can('delete_all'),
            'tvEnabled'       => $tvEnabled,
        ]);
    }

    public function show(int $id)
    {
        $ownMemberId = auth()->user()?->member_id;
        $isOwnProfile = ($id == $ownMemberId);

        if (!$isOwnProfile && !$this->can('view_all')) {
            abort(403);
        }

        $member = Member::with([
            'users',
            'accounts.variableSymbols',
            'accounts.accountAttribute',
            'addressPoint.town',
            'addressPoint.street',
            'ipAddresses.subnet',
            'speedClass',
        ])->find($id);
        if (!$member) {
            abort(404);
        }

        // Collect all variable symbols across all accounts
        $variableSymbols = $member->accounts
            ->flatMap(fn($a) => $a->variableSymbols)
            ->pluck('variable_symbol');

        // Credit account: attribute_id = 221100 ("Účet kreditu")
        $creditAccount = $member->accounts
            ->first(fn($a) => $a->account_attribute_id === AccountAttribute::CREDIT_ATTRIBUTE_ID);

        $mainUser = $member->users()->where('type', \App\Models\User::MAIN_USER)->first();
        $contacts = $mainUser
            ? $mainUser->contacts()->with('enumType')->get()
            : collect();

        // Active regular fee — member-specific, then config-based default by member type
        $activeMemberFee = MemberFee::where('member_id', $member->id)->active()->with('fee')->first();
        if (!$activeMemberFee) {
            $configKey    = 'default_fee_member_type_' . $member->type;
            $defaultFeeId = (int) \App\Models\Setting::get($configKey, 0);
            if ($defaultFeeId) {
                $defaultFee = \App\Models\Fee::find($defaultFeeId);
                if ($defaultFee) {
                    $activeMemberFee = new \App\Models\MemberFee();
                    $activeMemberFee->setRelation('fee', $defaultFee);
                }
            }
        }

        // Efektivní měsíční poplatek přes centrální resolver (shodné s tím, co se
        // reálně strhne): individuální → cena tarifu → základní. Zdroj pro popisek.
        [$monthlyFee, $monthlyFeeSource] = $this->resolveMonthlyFee((int) $member->id, (int) $member->type);

        // Dodatečné služby (např. veřejná IP) — strhávají se měsíčně navíc k tarifu
        // jako samostatná položka. Rozpis + součet pro zobrazení na kartě.
        $additionalServices      = \App\Services\AdditionalServicesResolver::items((int) $member->id, now()->toDateString());
        $additionalServicesTotal = array_sum(array_column($additionalServices, 'fee'));

        // Placená přípojná místa (povolené podsítě s vlastní cenou) — také měsíčně.
        $subnetFees      = \App\Services\AllowedSubnetFeesResolver::items((int) $member->id);
        $subnetFeesTotal = array_sum(array_column($subnetFees, 'fee'));

        // Detekce pozastaveného členství — má aktivní members_fees s fee.special_type_id=1
        $hasActiveInterrupt = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->where('mf.member_id', $id)
            ->where('f.special_type_id', 1)
            ->where('mf.activation_date', '<=', now()->toDateString())
            ->where('mf.deactivation_date', '>=', now()->toDateString())
            ->exists();

        return view('members.show', [
            'member'              => $member,
            'variableSymbols'     => $variableSymbols,
            'creditAccount'       => $creditAccount,
            'activeMemberFee'     => $activeMemberFee,
            'monthlyFee'          => $monthlyFee,
            'monthlyFeeSource'    => $monthlyFeeSource,
            'additionalServices'      => $additionalServices,
            'additionalServicesTotal' => $additionalServicesTotal,
            'subnetFees'          => $subnetFees,
            'subnetFeesTotal'     => $subnetFeesTotal,
            'hasActiveInterrupt'  => $hasActiveInterrupt,
            'tvEnabled'           => (bool) \App\Models\Setting::get('sledovanitv_enabled', 0),
            'canEdit'             => $this->can('edit_all'),
            'canDelete'           => $this->can('delete_all'),
            'mainUser'            => $mainUser,
            'contacts'            => $contacts,
            'canViewUser'         => $this->aclCheck('view_all', 'Users_Controller', 'users'),
            'canEditUser'         => $this->aclCheck('edit_all', 'Users_Controller', 'users'),
            'canNewUser'          => $this->aclCheck('new_all', 'Users_Controller', 'users'),
            'canViewGpon'         => $this->aclCheck('view_all', 'Gpon_Controller', 'gpon'),
            'canViewContacts'     => $this->aclCheck('view_all', 'Users_Controller', 'additional_contacts'),
            'canViewAccount'      => $this->aclCheck('view_all', 'Accounts_Controller', 'accounts'),
            'canEditVarSymbols'   => $this->aclCheck('view_all', 'Variable_Symbols_Controller', 'variable_symbols'),
            'canViewTransfers'    => $this->aclCheck('view_all', 'Accounts_Controller', 'transfers'),
            'canViewIpAddresses'  => $this->aclCheck('view_all', 'Ip_addresses_Controller', 'ip_address'),
            'canViewDevices'      => $isOwnProfile || $this->aclCheck('view_all', 'Devices_Controller', 'devices'),
            'canViewConnectionRequests' => (bool) Setting::get('connection_request_enable')
                && ($this->aclCheck('view_all', 'Connection_Requests_Controller', 'request')
                    || ($isOwnProfile && $this->aclCheck('view_own', 'Connection_Requests_Controller', 'request'))),
            'canViewFees'         => $this->aclCheck('view_all', 'Members_Controller', 'fees'),
            'canViewQos'           => $this->aclCheck('view_all', 'Members_Controller', 'qos_ceil'),
            'canViewAllowedSubnets'=> $isOwnProfile || $this->aclCheck('view_all', 'Allowed_subnets_Controller', 'allowed_subnet'),
            'canViewInvoices'      => $isOwnProfile || $this->aclCheck('view_all', 'Accounts_Controller', 'invoices'),
            'canNotify'            => $this->aclCheck('new_all', 'Notifications_Controller', 'member'),
            'canExportRegistration'=> $this->aclCheck('view_all', 'Members_Controller', 'registration_export'),
            'canViewComment'       => $this->aclCheck('view_all',   'Members_Controller', 'comment'),
            'canComment'           => $this->aclCheck('new_all',    'Members_Controller', 'comment'),
            'canEditComment'       => $this->aclCheck('edit_all',   'Members_Controller', 'comment'),
            'canDeleteComment'     => $this->aclCheck('delete_all', 'Members_Controller', 'comment'),
            'accountCommentsList'  => $creditAccount
                ? DB::table('comments as c')
                    ->join('users as u', 'u.id', '=', 'c.user_id')
                    ->where('c.comments_thread_id', $creditAccount->comments_thread_id)
                    ->orderByDesc('c.datetime')
                    ->selectRaw('c.id, c.text, c.datetime, CONCAT(u.surname, " ", u.name) as user_name')
                    ->get()
                : collect(),
            'accountComments'      => $creditAccount && $creditAccount->comments_thread_id
                ? DB::table('comments as c')
                    ->join('users as u', 'u.id', '=', 'c.user_id')
                    ->where('c.comments_thread_id', $creditAccount->comments_thread_id)
                    ->orderByDesc('c.datetime')
                    ->selectRaw('CONCAT(u.surname, " ", u.name, " (", DATE(c.datetime), "):\n", c.text) as line')
                    ->pluck('line')
                    ->implode("\n\n")
                : '',
            'gponOnts'             => \App\Models\Setting::get('gpon_enabled', '0')
                ? $member->onts()->where('reg_status', 'registered')->get()
                : collect(),
            'canViewInterrupts'    => $this->aclCheck('view_all', 'Members_Controller', 'membership_interrupts'),
            'canEditInterrupts'    => $this->aclCheck('edit_all', 'Members_Controller', 'membership_interrupts'),
            'interrupts'           => \Illuminate\Support\Facades\DB::table('membership_interrupts as mi')
                ->join('members_fees as mf', 'mf.id', '=', 'mi.members_fee_id')
                ->where('mi.member_id', $id)
                ->select('mi.*', 'mf.activation_date', 'mf.deactivation_date')
                ->orderBy('mf.activation_date')
                ->get(),
            'canViewWhitelists'    => $this->aclCheck('view_all', 'Members_whitelists_Controller', 'whitelist'),
            'canViewRedirect'      => $this->aclCheck('view_all',   'Redirect_Controller', 'redirect'),
            'canEditRedirect'      => $this->aclCheck('edit_all',   'Redirect_Controller', 'redirect'),
            'canDeleteRedirect'    => $this->aclCheck('delete_all', 'Redirect_Controller', 'redirect'),
            'memberRedirections'   => \App\Http\Controllers\RedirectController::getMemberRedirections($id),
            'expirationDate'       => $creditAccount?->getExpirationDate(),
            'memberContract'       => app(ContractService::class)->getByMemberId($id),
            'paymentInfo'          => app(\App\Services\PaymentQrService::class)->paymentInfoForMember($id),
            'paymentQrSvg'         => app(\App\Services\PaymentQrService::class)->svgForMember($id),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);

        $memberTypes = MemberType::labels();
        unset($memberTypes[MemberType::FORMER], $memberTypes[MemberType::APPLICANT]);

        $towns = Town::orderBy('town')->get();

        return view('members.create', compact('memberTypes', 'towns'));
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $request->validate([
            'name'                        => 'required|string|max:30',
            'surname'                     => 'nullable|string|max:60',
            'type'                        => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date'               => 'required|date',
            'login'                       => 'required|string|min:5|max:50|unique:users,login',
            'password'                    => 'required|string|min:6|confirmed',
            'email'                       => 'required|email|max:255',
            'phone'                       => 'required|string|max:40',
            'town_id'                     => 'required|integer|exists:towns,id',
            'street_id'                   => 'required|integer|exists:streets,id',
            'street_number'               => ['required', 'string', 'max:15', 'regex:/^(ev\.?\s*č\.?\s*)?\d[\dA-Za-z\/\- ]*$/iu'],
            'organization_identifier'     => ['nullable', 'string', 'max:20', 'regex:/^[^@\s]*$/u'],
            'vat_organization_identifier' => ['nullable', 'string', 'max:30', 'regex:/^[^@\s]*$/u'],
            'birthday'                    => 'required|date',
            'comment'                     => 'nullable|string|max:250',
        ], [
            'organization_identifier.regex'     => 'IČO nesmí obsahovat email ani mezery — zadejte pouze číslo IČO.',
            'vat_organization_identifier.regex' => 'DIČ nesmí obsahovat email ani mezery — zadejte pouze DIČ (např. CZ12345678).',
        ]);

        $memberId = null;

        DB::transaction(function () use ($request, &$memberId) {
            // 1. Adresní bod
            $addressPointId = DB::table('address_points')->insertGetId([
                'town_id'       => $request->town_id,
                'street_id'     => $request->street_id,
                'street_number' => $request->street_number,
                'country_id'    => 1,
            ]);

            // 2. Člen
            $fullName = $request->surname ? trim($request->name . ' ' . $request->surname) : $request->name;
            $memberId = DB::table('members')->insertGetId([
                'name'                        => $fullName,
                'type'                        => $request->type,
                'entrance_date'               => $request->entrance_date,
                'leaving_date'                => '9999-12-31',
                'comment'                     => $request->comment ?? '',
                'organization_identifier'     => $request->organization_identifier ?? '',
                'vat_organization_identifier' => $request->vat_organization_identifier ?? '',
                'address_point_id'            => $addressPointId,
                'locked'                      => 0,
                'registration'                => 0,
            ]);

            // 3. Uživatelský účet
            $userId = DB::table('users')->insertGetId([
                'member_id'            => $memberId,
                'name'                 => $request->name,
                'surname'              => $request->surname ?? '',
                'login'                => $request->login,
                'password'             => bcrypt($request->password),
                'application_password' => substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8),
                'type'                 => 1, // MAIN_USER
                'birthday'             => $request->birthday ?: null,
                'comment'              => '',
                'settings'             => '',
            ]);

            // 4. Kreditní účet člena
            $accountId = DB::table('accounts')->insertGetId([
                'member_id'            => $memberId,
                'account_attribute_id' => 221100, // Účet kreditu
                'balance'              => 0,
                'comment'              => '',
            ]);

            // 5. Variabilní symbol: member_id + 2 náhodné cifry
            $vs = (string) $memberId . str_pad((string) rand(0, 99), 2, '0', STR_PAD_LEFT);
            DB::table('variable_symbols')->insert([
                'account_id'      => $accountId,
                'variable_symbol' => $vs,
            ]);

            // 6. Výchozí třída rychlosti (regular_member_default)
            $defaultSpeedClassId = \App\Models\SpeedClass::where('regular_member_default', 1)->value('id') ?? 1;
            DB::table('members')->where('id', $memberId)->update([
                'speed_class_id' => $defaultSpeedClassId,
            ]);

            // 7. Kontakty — email (typ 20)
            if ($request->filled('email')) {
                $contactId = DB::table('contacts')->insertGetId([
                    'type'  => 20,
                    'value' => $request->email,
                ]);
                DB::table('users_contacts')->insert([
                    'user_id'          => $userId,
                    'contact_id'       => $contactId,
                    'mail_redirection' => 0,
                ]);
            }

            // 6. Kontakty — telefon (typ 21)
            if ($request->filled('phone')) {
                $contactId = DB::table('contacts')->insertGetId([
                    'type'  => 21,
                    'value' => $request->phone,
                ]);
                DB::table('users_contacts')->insert([
                    'user_id'          => $userId,
                    'contact_id'       => $contactId,
                    'mail_redirection' => 0,
                ]);
            }
        });

        \App\Services\AuditLogger::log('created', 'members', $memberId, null, [
            'name'          => $request->surname ? trim($request->name . ' ' . $request->surname) : $request->name,
            'type'          => $request->type,
            'entrance_date' => $request->entrance_date,
            'login'         => $request->login,
        ]);

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Člen byl úspěšně vytvořen.');
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $member = Member::find($id);
        if (!$member) {
            abort(404);
        }

        $member->load('addressPoint.town', 'addressPoint.street');

        $types   = MemberType::labels();
        $towns   = Town::orderBy('town')->get();
        $streets = Street::orderBy('street')->get();

        $speedClasses      = \App\Models\SpeedClass::orderBy('name')->get();
        $defaultSpeedClass = \App\Models\SpeedClass::where('regular_member_default', true)->first();
        $canEditQos        = $this->aclCheck('edit_all', 'Members_Controller', 'qos_ceil');

        return view('members.edit', compact('member', 'types', 'towns', 'streets', 'speedClasses', 'defaultSpeedClass', 'canEditQos'));
    }

    public function update(Request $request, int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $member = Member::find($id);
        if (!$member) {
            abort(404);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'type'           => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date'  => 'nullable|date',
            'leaving_date'   => 'nullable|date_format:Y-m-d',
            'comment'        => 'nullable|string|max:250',
            'organization_identifier'     => ['nullable', 'string', 'max:20', 'regex:/^[^@\s]*$/u'],
            'vat_organization_identifier' => ['nullable', 'string', 'max:30', 'regex:/^[^@\s]*$/u'],
            'town_id'         => 'nullable|integer|exists:towns,id',
            'street_id'       => 'nullable|integer|exists:streets,id',
            'street_number'   => ['nullable', 'string', 'max:15', 'regex:/^(ev\.?\s*č\.?\s*)?\d[\dA-Za-z\/\- ]*$/iu'],
            'speed_class_id'         => 'nullable|integer|exists:speed_classes,id',
        ], [
            'organization_identifier.regex'     => 'IČO nesmí obsahovat email ani mezery — zadejte pouze číslo IČO.',
            'vat_organization_identifier.regex' => 'DIČ nesmí obsahovat email ani mezery — zadejte pouze DIČ (např. CZ12345678).',
        ]);

        $member->update([
            'name'                        => $data['name'],
            'type'                        => $data['type'],
            'entrance_date'               => $data['entrance_date'] ?? null,
            'leaving_date'                => $request->leaving_date ?: '9999-12-31',
            'comment'                     => $data['comment'] ?? null,
            'organization_identifier'     => $data['organization_identifier'] ?? null,
            'vat_organization_identifier' => $data['vat_organization_identifier'] ?? null,
            'locked'                      => $request->boolean('locked'),
            'registration'                => $request->boolean('registration'),
            'speed_class_id'              => $data['speed_class_id'] ?? null,
            'notification_by_redirection' => $request->boolean('notification_by_redirection'),
            'notification_by_email'       => $request->boolean('notification_by_email'),
            'notification_by_sms'         => $request->boolean('notification_by_sms'),
        ]);

        // Update or create address point
        if ($request->filled('town_id')) {
            $addressPoint = $member->addressPoint ?? new AddressPoint();
            $addressPoint->town_id       = $data['town_id'];
            $addressPoint->street_id     = $data['street_id'] ?? null;
            $addressPoint->street_number = $data['street_number'] ?? null;
            if (!$addressPoint->exists) {
                $addressPoint->country_id = 1;
            }
            $addressPoint->save();

            if ($member->address_point_id !== $addressPoint->id) {
                $member->address_point_id = $addressPoint->id;
                $member->save();
            }
        }

        IpAddress::where('member_id', $id)
            ->with('subnet')
            ->get()
            ->each(fn($ip) => $ip->subnet?->setExpired());

        session()->flash('success', 'Člen byl úspěšně upraven.');

        return redirect()->route('members.show', $id);
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        $formerTypes  = [MemberType::FORMER, MemberType::FORMER_CUSTOMER];
        $pendingTypes = [MemberType::PENDING_MEMBER, MemberType::PENDING_CUSTOMER];

        // Čekající členové (17/18) — smazat okamžitě bez kroku "označit jako bývalého"
        if (in_array($member->type, $pendingTypes)) {
            DB::transaction(function () use ($id) {
                $userIds    = DB::table('users')->where('member_id', $id)->pluck('id');
                $contactIds = DB::table('users_contacts')->whereIn('user_id', $userIds)->pluck('contact_id');
                DB::table('users_contacts')->whereIn('user_id', $userIds)->delete();
                DB::table('contacts')->whereIn('id', $contactIds)->delete();
                foreach ($userIds as $userId) {
                    $deviceIds = DB::table('devices')->where('user_id', $userId)->pluck('id');
                    foreach ($deviceIds as $deviceId) {
                        $ifaceIds = DB::table('ifaces')->where('device_id', $deviceId)->pluck('id');
                        foreach ($ifaceIds as $ifaceId) {
                            DB::table('ip6_addresses')->where('iface_id', $ifaceId)->delete();
                            DB::table('ip_addresses')->where('iface_id', $ifaceId)->delete();
                        }
                        DB::table('ifaces')->where('device_id', $deviceId)->delete();
                    }
                    DB::table('devices')->where('user_id', $userId)->delete();
                }
                DB::table('users')->where('member_id', $id)->delete();
                $accountIds = DB::table('accounts')->where('member_id', $id)->pluck('id');
                DB::table('variable_symbols')->whereIn('account_id', $accountIds)->delete();
                DB::table('transfers')->whereIn('origin_id', $accountIds)->delete();
                DB::table('transfers')->whereIn('destination_id', $accountIds)->delete();
                DB::table('accounts')->where('member_id', $id)->delete();
                DB::table('members_fees')->where('member_id', $id)->delete();
                DB::table('allowed_subnets')->where('member_id', $id)->delete();
                DB::table('members')->where('id', $id)->delete();
            });
            $this->purgeUnsignedContracts($id);
            \App\Services\AuditLogger::log('deleted', 'members', $id, (array) $member, null);
            return redirect()->route('members.index')
                ->with('success', 'Čekající člen byl smazán.');
        }

        // Krok 1: pokud není ještě bývalý, označit jako bývalého
        if (!in_array($member->type, $formerTypes)) {
            // typ 90 (řádný člen) → 16 (bývalý zákazník), ostatní → 15 (bývalý člen)
            $newType = ($member->type == MemberType::REGULAR) ? MemberType::FORMER_CUSTOMER : MemberType::FORMER;
            $leaving = now()->format('Y-m-d');
            DB::table('members')->where('id', $id)->update([
                'type'           => $newType,
                'locked'         => 1,
                'leaving_date'   => $leaving,
                'speed_class_id' => null, // bývalý člen → rychlost „žádná"
            ]);
            \App\Services\AuditLogger::log('updated', 'members', $id, [
                'type'           => $member->type,
                'locked'         => $member->locked,
                'leaving_date'   => $member->leaving_date,
                'speed_class_id' => $member->speed_class_id,
            ], [
                'type'           => $newType,
                'locked'         => 1,
                'leaving_date'   => $leaving,
                'speed_class_id' => null,
            ]);
            // Individuální tarif a dodatečné služby ukončit k datu odchodu.
            \App\Services\MemberFeesTermination::deactivate($id, $leaving);
            // Výpověď zákazníka → podepsané smlouvy označit jako ukončené.
            $terminated = $this->terminateSignedContracts($id, $leaving);
            // Rozpracované návrhy bývalého člena už nikdo nedokončí → smazat.
            $this->purgeUnsignedContracts($id);
            $label = ($newType === MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
            $note  = "Člen byl označen jako bývalý {$label}. Pro úplné smazání klikněte znovu na Trvale smazat.";
            if ($terminated > 0) {
                $note .= ' Smlouva byla označena jako ukončená.';
            }
            return redirect()->route('members.show', $id)->with('info', $note);
        }

        // Krok 2: člen je již bývalý — smazat vše
        DB::transaction(function () use ($id) {
            $userIds = DB::table('users')->where('member_id', $id)->pluck('id');

            // Kontakty
            $contactIds = DB::table('users_contacts')->whereIn('user_id', $userIds)->pluck('contact_id');
            DB::table('users_contacts')->whereIn('user_id', $userIds)->delete();
            DB::table('contacts')->whereIn('id', $contactIds)->delete();

            // Zařízení, rozhraní, IP adresy
            foreach ($userIds as $userId) {
                $deviceIds = DB::table('devices')->where('user_id', $userId)->pluck('id');
                foreach ($deviceIds as $deviceId) {
                    $ifaceIds = DB::table('ifaces')->where('device_id', $deviceId)->pluck('id');
                    foreach ($ifaceIds as $ifaceId) {
                        DB::table('ip6_addresses')->where('iface_id', $ifaceId)->delete();
                            DB::table('ip_addresses')->where('iface_id', $ifaceId)->delete();
                    }
                    DB::table('ifaces')->where('device_id', $deviceId)->delete();
                }
                DB::table('devices')->where('user_id', $userId)->delete();
            }

            // Uživatelé
            DB::table('users')->where('member_id', $id)->delete();

            // Účty a převody
            $accountIds = DB::table('accounts')->where('member_id', $id)->pluck('id');
            DB::table('transfers')->whereIn('origin_id', $accountIds)->delete();
            DB::table('transfers')->whereIn('destination_id', $accountIds)->delete();
            DB::table('accounts')->where('member_id', $id)->delete();

            // Variabilní symboly (přes account_id)
            DB::table('variable_symbols')->whereIn('account_id', $accountIds)->delete();

            // Poplatky
            DB::table('members_fees')->where('member_id', $id)->delete();

            // Povolené podsítě
            DB::table('allowed_subnets')->where('member_id', $id)->delete();

            // Člen
            DB::table('members')->where('id', $id)->delete();
        });

        $this->purgeUnsignedContracts($id);
        \App\Services\AuditLogger::log('deleted', 'members', $id, (array) $member, null);

        return redirect()->route('members.index')
            ->with('success', 'Člen a všechna jeho data byla trvale smazána.');
    }

    /**
     * Při trvalém smazání člena smaž jeho nepodepsané smlouvy z contractsdb
     * (návrh / čeká na podpis / ověřeno / zrušená), aby nezůstávaly "viset"
     * bez existujícího člena. Podepsané (`signed`) i ukončené (`terminated`)
     * smlouvy zůstávají jako právní dokument.
     *
     * Smlouvy jsou v samostatné DB (connection 'contracts'), takže to nejde
     * dovnitř transakce nad `mysql`. Chyba contractsdb nesmí shodit už
     * dokončené mazání člena — proto try/catch. Child tabulky
     * (contract_parties/events/otps) se domažou přes ON DELETE CASCADE.
     */
    private function purgeUnsignedContracts(int $memberId): void
    {
        try {
            \App\Models\Contract::where('member_id', $memberId)
                ->whereNotIn('status', ['signed', 'terminated'])
                ->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Nepodařilo se smazat nepodepsané smlouvy člena #' . $memberId . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Výpověď zákazníka: podepsané smlouvy člena označit jako `terminated`
     * ("Ukončená"). Smlouva zůstává v systému jako právní dokument (PDF).
     * Robustní vůči výpadku contractsdb (try/catch) — nesmí shodit ukončení
     * člena. Vrací počet ukončených smluv.
     */
    private function terminateSignedContracts(int $memberId, ?string $leavingDate): int
    {
        try {
            $contracts = \App\Models\Contract::where('member_id', $memberId)
                ->where('status', 'signed')
                ->get();

            foreach ($contracts as $contract) {
                $contract->update(['status' => 'terminated']);
                \App\Models\ContractEvent::create([
                    'contract_id' => $contract->id,
                    'event'       => 'terminated',
                    'meta_json'   => json_encode([
                        'by'     => auth()->user()?->login,
                        'reason' => 'Ukončení zákazníka',
                        'date'   => $leavingDate,
                    ], JSON_UNESCAPED_UNICODE),
                ]);
            }

            return $contracts->count();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Nepodařilo se ukončit smlouvy člena #' . $memberId . ': ' . $e->getMessage()
            );
            return 0;
        }
    }

    public function applicants()
    {
        abort_unless($this->can('view_all'), 403);

        $pending = DB::table('members as m')
            ->whereIn('m.type', [17, 18])
            ->where(function ($q) {
                $q->where('m.leaving_date', '9999-12-31')
                  ->orWhere('m.leaving_date', '0000-00-00');
            })
            ->orderByDesc('m.id')
            ->select('m.id', 'm.name', 'm.type', 'm.entrance_date', 'm.registration')
            ->get();

        return view('members.applicants', compact('pending'));
    }

    /**
     * Seznam členů označených k ukončení (cron MarkPendingTermination, 14. den
     * každého měsíce — VOP: 1 měsíc neplacení = automatický konec smlouvy).
     * Admin každý případ projde a klikne 'Ukončit' (link na endMembershipForm
     * s předvyplněným leaving_date) nebo 'Reset blokace' pokud chce dát milost.
     */
    public function pendingTermination()
    {
        abort_unless($this->can('view_all'), 403);

        $rows = DB::table('members as m')
            ->join('accounts as a', function ($j) {
                $j->on('a.member_id', '=', 'm.id')->where('a.account_attribute_id', 221100);
            })
            ->leftJoin('variable_symbols as vs', 'vs.account_id', '=', 'a.id')
            ->where('m.pending_termination', 1)
            ->whereIn('m.type', [2, 90])
            ->select(
                'm.id', 'm.name', 'm.type',
                'm.payment_blocked_since',
                'a.balance',
                DB::raw('GROUP_CONCAT(vs.variable_symbol) AS variable_symbols')
            )
            ->groupBy('m.id', 'm.name', 'm.type', 'm.payment_blocked_since', 'a.balance')
            ->orderBy('m.payment_blocked_since')
            ->get();

        return view('members.pending_termination', [
            'rows'    => $rows,
            'today'   => now()->format('Y-m-d'),
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    public function endMembershipForm(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);
        abort_if(in_array($member->type, [15, 16]), 400, 'Člen již byl ukončen.');

        $account = DB::table('accounts')
            ->where('member_id', $id)
            ->where('account_attribute_id', 221100)
            ->first(['id', 'balance']);

        // Předvyplnit účet, na který se má vrátit přeplatek. Logika:
        // 1) Vzít poslední bankovní platbu, která reálně přistála na kreditním
        //    účtu PRÁVĚ TOHOTO člena (chain: dependent transfer → previous transfer
        //    → bank_transfer.origin). To pokrývá případy, kdy má jeden plátce
        //    v `bank_accounts` víc záznamů a jen některé reálně používal pro
        //    tenhle členský účet (např. zvlášť pro type 2 vs type 90).
        // 2) Pokud žádná taková platba neexistuje (např. nový člen bez plateb
        //    nebo manuálně vložený kredit), fallback na nejnovější platný
        //    `bank_accounts` záznam tohoto člena.
        // 3) Pokud ani ten není, prázdné pole — admin vyplní ručně.
        $lastFromAccount = DB::table('accounts AS a')
            ->join('transfers AS t2',         't2.destination_id', '=', 'a.id')
            ->join('transfers AS t1',         't1.id', '=', 't2.previous_transfer_id')
            ->join('bank_transfers AS bt',    'bt.transfer_id', '=', 't1.id')
            ->join('bank_accounts AS ba',     'ba.id', '=', 'bt.origin_id')
            ->whereNull('bt.deleted_at')
            ->where('a.member_id', $id)
            ->where('a.account_attribute_id', 221100)
            ->whereNotNull('ba.account_nr')
            ->whereNotNull('ba.bank_nr')
            ->where('ba.account_nr', '!=', '')
            ->where('ba.bank_nr',    '!=', '')
            ->orderByDesc('t2.datetime')
            ->first(['ba.account_nr', 'ba.bank_nr']);

        $bankAccount = $lastFromAccount ?: DB::table('bank_accounts')
            ->where('member_id', $id)
            ->whereNotNull('account_nr')
            ->whereNotNull('bank_nr')
            ->where('account_nr', '!=', '')
            ->where('bank_nr', '!=', '')
            ->orderByDesc('id')
            ->first(['account_nr', 'bank_nr']);

        $refundAccount = $bankAccount
            ? trim($bankAccount->account_nr) . '/' . trim($bankAccount->bank_nr)
            : '';

        // Pro budoucí leaving_date cron `fees:deduct` stihne strhnout tarif za každý
        // deduct_day, který padne před datum vystoupení (where leaving_date > deduct_date).
        // Předpočítáme měsíční tarif a v JS pak rebalanc refund_amount podle vybraného data,
        // ať admin nevrátí peníze, které DB stejně příští srážka zruší.
        $monthlyFee = (float) DB::table('members_fees AS mf')
            ->join('fees AS f', 'f.id', '=', 'mf.fee_id')
            ->join('enum_types AS et', 'et.id', '=', 'f.type_id')
            ->whereRaw('LOWER(et.value) = ?', ['regular member fee'])
            ->where('mf.member_id', $id)
            ->whereDate('mf.activation_date', '<=', now())
            ->whereDate('mf.deactivation_date', '>=', now())
            ->orderBy('mf.priority')
            ->value('f.fee');
        if ($monthlyFee <= 0) {
            $defaultFeeId = (int) \App\Models\Setting::get('default_fee_member_type_' . $member->type, 0);
            $monthlyFee   = $defaultFeeId
                ? (float) DB::table('fees')->where('id', $defaultFeeId)->value('fee')
                : 0.0;
        }

        $deductDay = (int) \App\Models\Setting::get('deduct_day', 26);

        // Detekce zda už dnešní cron běžel (transfer s type=1 a datetime=dnes na účtu
        // tohoto člena). Pokud běžel a dnes je deduct_day, nepočítáme ho do budoucích srážek.
        $todayDeducted = $account
            ? DB::table('transfers')
                ->where('origin_id', $account->id)
                ->where('type', 1)
                ->whereDate('datetime', now()->toDateString())
                ->exists()
            : false;

        return view('members.end_membership', [
            'member'        => $member,
            'balance'       => $account?->balance ?? 0,
            'refundAccount' => $refundAccount,
            'today'         => now()->format('Y-m-d'),
            'monthlyFee'    => $monthlyFee,
            'deductDay'     => $deductDay,
            'todayDeducted' => $todayDeducted,
        ]);
    }

    public function endMembership(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $isRefundMode = (int) $request->input('end_mode') === 3;

        $validated = $request->validate([
            'leaving_date'   => 'required|date',
            'end_mode'       => 'required|integer|in:1,2,3,4',
            'refund_account' => $isRefundMode ? 'required|string|max:50' : 'nullable',
            'refund_amount'  => $isRefundMode ? 'required|numeric|gt:0' : 'nullable',
        ]);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        $newType = ($member->type == MemberType::REGULAR) ? MemberType::FORMER : MemberType::FORMER_CUSTOMER;
        $endMode = (int) $validated['end_mode'];

        // Před otevřením transakce ověřit, že existuje bankovní účet pro tento typ člena.
        // Bez něj by se outgoing_payment nevytvořil, ale credit by se vynuloval a email
        // odešel — admin by pak hledal "kam zmizely peníze".
        if ($endMode === 3) {
            $configKey = 'bank_account_member_type_' . $member->type;
            if (!(int) \App\Models\Setting::get($configKey, 0)) {
                return redirect()->back()->withInput()->withErrors([
                    'refund_account' => "Není nakonfigurován bankovní účet pro typ člena {$member->type} "
                        . "(setting '{$configKey}'). Nastavte ho v Nastavení → Banka, pak vratku zopakujte."
                ]);
            }
        }

        // Zamknout přihlášení do portálu jen pokud leaving_date už nastalo.
        // Pro budoucí datum (typicky "dohrání služby do data odjezdu") nechat
        // locked=0 — cron RedirectFormerMembers zamkne v den D, stejně jako
        // aktivuje redirect internetu a smazání zařízení. Pattern viz Kohana
        // members.php:4076 (if leaving_date <= today { locked = 1 }).
        $lockNow = $validated['leaving_date'] <= now()->format('Y-m-d') ? 1 : 0;

        DB::transaction(function () use ($id, $member, $newType, $validated, $endMode, $lockNow) {
            // Při ukončení reset prepaid flagů — bývalý člen už není kandidát
            // na ukončení (vzhledem k tomu, že právě byl ukončen) ani na
            // redirect pro nedostatek kreditu. Bez resetu zůstanou v menu/UI
            // viset jako nesoulad: type=15/16 ale pending_termination=1.
            DB::table('members')->where('id', $id)->update([
                'type'                  => $newType,
                'locked'                => $lockNow,
                'leaving_date'          => $validated['leaving_date'],
                'payment_blocked'       => 0,
                'payment_blocked_since' => null,
                'pending_termination'   => 0,
                'speed_class_id'        => null, // bývalý člen → rychlost „žádná"
            ]);
            \App\Services\AuditLogger::log('updated', 'members', $id, [
                'type'         => $member->type,
                'locked'       => $member->locked,
                'leaving_date' => $member->leaving_date,
            ], [
                'type'         => $newType,
                'locked'       => $lockNow,
                'leaving_date' => $validated['leaving_date'],
                'end_mode'     => $endMode,
            ]);
            // Když měl aktivní payment_blocked redirect, smaž ho.
            app(\App\Services\PaymentBlockedRedirectService::class)->refreshForMember($id);

            // Individuální tarif a dodatečné služby datumově ukončit k datu odchodu
            // (i budoucímu) — jinak by bývalý člen dál „platil" a visel v seznamech.
            \App\Services\MemberFeesTermination::deactivate($id, $validated['leaving_date']);

            // Přidat +U k variabilnímu symbolu (jako Kohana)
            $accountId = DB::table('accounts')
                ->where('member_id', $id)
                ->where('account_attribute_id', 221100)
                ->value('id');
            if ($accountId) {
                $vsRow = DB::table('variable_symbols')
                    ->where('account_id', $accountId)
                    ->orderBy('id')
                    ->first();
                if ($vsRow && !str_ends_with($vsRow->variable_symbol, '+U')) {
                    DB::table('variable_symbols')
                        ->where('id', $vsRow->id)
                        ->update(['variable_symbol' => $vsRow->variable_symbol . '+U']);
                }
            }

            // Mód 3: vratka — vložit do outgoing_payments + pohoda_refund_queue.
            // Pro zákazníka (CUSTOMER=2) navíc vystavit vratnou fakturu (dobropis).
            $refundInvoicePdf = null;
            $refundDocNumber  = null;
            if ($endMode === 3 && !empty($validated['refund_account']) && ($validated['refund_amount'] ?? 0) > 0) {
                $configKey     = 'bank_account_member_type_' . $member->type;
                $bankAccountId = (int) \App\Models\Setting::get($configKey, 0);

                if ($bankAccountId) {
                    $refundAmount = round((float)$validated['refund_amount'], 2);

                    $outgoingPaymentId = DB::table('outgoing_payments')->insertGetId([
                        'bank_account_id' => $bankAccountId,
                        'target_account'  => $validated['refund_account'],
                        'amount'          => $refundAmount,
                        'currency'        => 'CZK',
                        'message'         => 'Vratka při ukončení členství',
                        'reason'          => 'termination_refund',
                        'status'          => 'draft',
                        'created_by'      => auth()->id() ?? 1,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);

                    // Vygeneruj číslo dobropisu podle typu člena (stejně jako legacy Kohana):
                    //   - CUSTOMER (2)   → "YY###"    (např. 26011)
                    //   - REGULAR  (90)  → "YYČL####" (např. 26ČL0010)
                    $isCustomer       = in_array($member->type, [MemberType::CUSTOMER, MemberType::PENDING_CUSTOMER], true);
                    $refundDocNumber  = $this->nextRefundDocNumber($isCustomer);

                    // PDF dobropisu — pro oba typy. Záměrně NEvkládáme řádek do invoices —
                    // vratka je samostatný typ dokumentu (creditNote v Pohodě), stejný
                    // pattern jako legacy Kohana Refund_Pdf lib.
                    $refundInvoicePdf = (new \App\Services\RefundPdfService())->generate(
                        $id,
                        $refundDocNumber,
                        (int) $member->type,
                        (string) $validated['leaving_date'],
                        (string) $validated['refund_account'],
                        $refundAmount
                    );

                    // Vždy zápis do pohoda_refund_queue (zákazník i člen)
                    DB::table('pohoda_refund_queue')->insert([
                        'member_id'           => $id,
                        'member_type'         => $member->type,  // PŮVODNÍ typ (2 nebo 90), ne FORMER
                        'doc_number'          => $refundDocNumber,
                        'outgoing_payment_id' => $outgoingPaymentId,
                        'refund_account'      => $validated['refund_account'],
                        'amount'              => $refundAmount,
                        'currency'            => 'CZK',
                        'reason'              => 'termination_refund',
                        'note'                => 'Vratka při ukončení ' . ($isCustomer ? 'smlouvy' : 'členství'),
                        'created_at'          => now(),
                        'status'              => 'new',
                    ]);

                    // Vymazání kreditního účtu člena
                    $creditAccount = DB::table('accounts')
                        ->where('member_id', $id)
                        ->where('account_attribute_id', 221100)
                        ->first();

                    if ($creditAccount && $creditAccount->balance != 0) {
                        $clearAmount = $creditAccount->balance;
                        $orgAccount  = DB::table('accounts')
                            ->where('member_id', 1)
                            ->where('account_attribute_id', 221101)
                            ->first();

                        if ($orgAccount) {
                            DB::table('transfers')->insert([
                                'origin_id'         => $creditAccount->id,
                                'destination_id'    => $orgAccount->id,
                                'type'              => 0,
                                'amount'            => abs($clearAmount),
                                'datetime'          => now()->format('Y-m-d H:i:s'),
                                'creation_datetime' => now()->format('Y-m-d H:i:s'),
                                'text'              => 'Vymazání kreditu při ukončení členství s vratkou',
                                'member_id'         => $id,
                            ]);
                            // Double-entry: origin (credit) -=, destination (operating) +=.
                            // Hard-set creditu na 0 by selhal pokud by mezitím přišla další platba,
                            // ale tahle sekce běží uvnitř DB::transaction, takže ne. Pro consistency
                            // s createTransfer v ImportController používáme decrement/increment dvojici.
                            DB::table('accounts')->where('id', $creditAccount->id)->decrement('balance', $clearAmount);
                            DB::table('accounts')->where('id', $orgAccount->id)->increment('balance', $clearAmount);
                        }
                    }
                }
            }

            // Auto device remove — pokud leaving_date <= dnes a nastavení povoluje
            if (\App\Models\Setting::get('former_member_auto_device_remove', '0') == '1'
                && $validated['leaving_date'] <= now()->toDateString()) {

                // 1. Sbírej IP adresy (přes member_id + přes ifaces→devices→users)
                $ips1 = DB::table('ip_addresses')
                    ->where('member_id', $id)
                    ->whereNotNull('ip_address')
                    ->pluck('ip_address');

                $ips2 = DB::table('ip_addresses as ia')
                    ->join('ifaces as i', 'i.id', '=', 'ia.iface_id')
                    ->join('devices as d', 'd.id', '=', 'i.device_id')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('u.member_id', $id)
                    ->whereNotNull('ia.ip_address')
                    ->pluck('ia.ip_address');

                $ips = $ips1->merge($ips2)->unique()->sort()->values()->all();

                // 2. Zapiš IP adresy jako prefix do members.comment
                $today  = now()->format('Y-m-d');
                $prefix = "[{$today}] ";
                $maxLen = 250;
                $out    = $prefix;
                $shown  = 0;

                foreach ($ips as $ip) {
                    $add = ($shown ? ',' : '') . $ip;
                    if (strlen($out . $add) > $maxLen) break;
                    $out .= $add;
                    $shown++;
                }

                $rest = count($ips) - $shown;
                if ($rest > 0) {
                    $suffix = " (+{$rest} další)";
                    if (strlen($out . $suffix) > $maxLen) {
                        $out = rtrim(substr($out, 0, max(0, $maxLen - strlen($suffix))), ', ');
                    }
                    $out .= $suffix;
                }

                if (empty($ips)) {
                    $out = $prefix . '(žádné)';
                }

                DB::statement("
                    UPDATE members
                    SET comment = LEFT(
                        CONCAT(?, IF(comment IS NULL OR comment = '', '', '\n'), IFNULL(comment, '')),
                        250
                    )
                    WHERE id = ?
                ", [$out, $id]);

                // 3. Smaž zařízení (ifaces → ip_addresses, ip6_addresses kaskádně)
                $userIds = DB::table('users')->where('member_id', $id)->pluck('id');
                $deviceIds = DB::table('devices')->whereIn('user_id', $userIds)->pluck('id');
                foreach ($deviceIds as $deviceId) {
                    $ifaceIds = DB::table('ifaces')->where('device_id', $deviceId)->pluck('id');
                    foreach ($ifaceIds as $ifaceId) {
                        DB::table('ip6_addresses')->where('iface_id', $ifaceId)->delete();
                        DB::table('ip_addresses')->where('iface_id', $ifaceId)->delete();
                    }
                    DB::table('ifaces')->where('device_id', $deviceId)->delete();
                }
                DB::table('devices')->whereIn('user_id', $userIds)->delete();

                // 4. Smaž ip_addresses přímo přes member_id
                DB::table('ip_addresses')->where('member_id', $id)->delete();

                // 5. Smaž subnets_owners
                DB::table('subnets_owners')->where('member_id', $id)->delete();
            }

            // Odeslat email pro módy 2, 3, 4
            if (in_array($endMode, [2, 3, 4])) {
                $messageId = match($endMode) {
                    3 => 96, // Vrácení platby bývalému členovi
                    2 => 94, // Bývalý člen bez platby (neplacení)
                    4 => 34, // Ukončení na vlastní žádost
                    default => 94,
                };

                $message = \App\Models\Message::where('id', $messageId)->first();
                if ($message && $message->email_text) {
                    $userId = DB::table('users')->where('member_id', $id)->value('id');
                    if ($userId) {
                        $email = DB::table('contacts as c')
                            ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                            ->where('uc.user_id', $userId)
                            ->where('c.type', 20)
                            ->value('c.value');

                        if ($email) {
                            $extra = [];
                            if ($endMode === 3) {
                                $extra['ucet']    = $validated['refund_account'] ?? '';
                                $extra['balance'] = number_format(
                                    (float) ($validated['refund_amount'] ?? 0),
                                    2, ',', ' '
                                );
                            }
                            $body = \App\Models\Message::substitute(
                                $message->email_text,
                                \App\Models\Message::buildPlaceholders($id, $extra)
                            );
                            $prefix = \App\Models\Setting::get('email_subject_prefix', '');
                            $emailQueueId = DB::table('email_queues')->insertGetId([
                                'from'    => \App\Models\Setting::get('email_default_email', 'noreply@pvfree.net'),
                                'to'      => $email,
                                'subject' => ($prefix ? $prefix . ' :: ' : '') . $message->name,
                                'body'    => $body,
                                'state'   => 0,
                            ]);

                            // Pro zákazníka při vratce přilož PDF dobropisu k emailu.
                            // $refundInvoicePdf a $refundDocNumber jsou nastavené výš v sekci
                            // outgoing_payment, pokud se opravdu vystavila faktura.
                            if ($endMode === 3 && $refundInvoicePdf && file_exists($refundInvoicePdf)) {
                                DB::table('email_queue_attachments')->insert([
                                    'email_queue_id' => $emailQueueId,
                                    'path'           => $refundInvoicePdf,
                                    'name'           => 'dobropis_' . $refundDocNumber . '.pdf',
                                    'mime'           => 'application/pdf',
                                    'created_at'     => now(),
                                ]);
                            }
                        }
                    }
                }
            }
        });

        // Ukončení členství/výpověď → podepsané smlouvy člena označit jako ukončené
        // (mimo transakci — contracts jsou v samostatné DB, robustní vůči výpadku).
        $terminated = $this->terminateSignedContracts($id, (string) $validated['leaving_date']);
        // Rozpracované návrhy bývalého člena už nikdo nedokončí → smazat, ať neviší.
        $this->purgeUnsignedContracts($id);

        $label = ($newType == MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
        $note  = "Členství bylo ukončeno. {$member->name} označen jako bývalý {$label}.";
        if ($terminated > 0) {
            $note .= ' Smlouva byla označena jako ukončená.';
        }
        return redirect()->route('members.show', $id)->with('success', $note);
    }

    /**
     * Vrátí další číslo dobropisu (refund doc_number) v aktuálním roce.
     *  - Zákazník: "YY###"     (např. 26011 = rok 26, sekvence 011)
     *  - Člen:     "YYČL####"  (např. 26ČL0010)
     * Sekvence je per-rok + per-formát (ne per member_type — viz níž).
     *
     * Filtr přes `doc_number` LIKE/NOT LIKE sám o sobě jednoznačně rozlišuje
     * zákazníka od člena, takže explicitní `member_type` filtr je zbytečný a
     * navíc nebezpečný: PENDING_CUSTOMER (18) i HONORARY/FEE_FREE dostávají
     * zákaznický/členský formát, ale do queue se ukládá jejich vlastní typ a
     * filtr by je vynechal → duplicitní sekvence.
     *
     * lockForUpdate() drží gap-lock přes celou MAX-skupinu do commitu transakce
     * — chrání proti tomu, aby dvě paralelní endMembership() volání přečetla
     * stejné MAX a vygenerovala stejné číslo. Musí se volat uvnitř transakce.
     * RIGHT() místo SUBSTRING() ať MySQL korektně rozezná multibyte 'Č'.
     */
    private function nextRefundDocNumber(bool $isCustomer): string
    {
        $year2 = date('y');
        if ($isCustomer) {
            $maxSeq = (int) DB::table('pohoda_refund_queue')
                ->where('doc_number', 'like', $year2 . '%')
                ->where('doc_number', 'not like', $year2 . 'ČL%')
                ->lockForUpdate()
                ->selectRaw('MAX(CAST(RIGHT(doc_number, 3) AS UNSIGNED)) AS m')
                ->value('m');
            return $year2 . str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT);
        }

        $prefix = $year2 . 'ČL';
        $maxSeq = (int) DB::table('pohoda_refund_queue')
            ->where('doc_number', 'like', $prefix . '%')
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(RIGHT(doc_number, 4) AS UNSIGNED)) AS m')
            ->value('m');
        return $prefix . str_pad((string) ($maxSeq + 1), 4, '0', STR_PAD_LEFT);
    }

    public function restore(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        if (!in_array($member->type, [MemberType::FORMER, MemberType::FORMER_CUSTOMER])) {
            return back()->with('error', 'Tento člen není označen jako bývalý.');
        }

        // 16 → 90 (zákazník), 15 → 2 (člen)
        $originalType = ($member->type == MemberType::FORMER_CUSTOMER) ? MemberType::REGULAR : MemberType::CUSTOMER;
        $oldLeaving   = $member->leaving_date;

        DB::table('members')->where('id', $id)->update([
            'type'         => $originalType,
            'locked'       => 0,
            'leaving_date' => '9999-12-31',
        ]);

        // Poplatky, které automatika ukončila k datu odchodu, zase oživit.
        \App\Services\MemberFeesTermination::reactivate($id, $oldLeaving);

        $label = ($originalType == MemberType::REGULAR) ? 'zákazník' : 'člen';
        return redirect()->route('members.show', $id)
            ->with('success', "Člen byl obnoven jako {$label}.");
    }

    /**
     * Obnovit pozastavené členství (interrupt fee aktivní, fee_id->special_type_id=1).
     *  - members.leaving_date → 9999-12-31, locked/payment_blocked/pending_termination → 0
     *  - members_fees: deactivation_date → effective_date − 1 (přerušení končí dnem -1)
     *  - membership_interrupts: end_after_interrupt_end → 0 (zruší plánovanou terminaci)
     *  - smaže messages_ip_addresses pro IP členů
     *  - strhne plný měsíční poplatek s datetime = effective_date
     *
     * effective_date: dnes (immediate) nebo budoucí datum. Pokud effective_date
     * spadne na deduct_day, cron DeductFees uvidí náš transfer (idempotency na
     * origin+type+datetime) a strhne pouze jednou.
     */
    public function restoreInterrupt(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        $today = now()->toDateString();
        $maxDate = now()->addDays(60)->toDateString();

        $validated = $request->validate([
            'effective_date' => ['nullable', 'date', 'after_or_equal:' . $today, 'before_or_equal:' . $maxDate],
        ], [
            'effective_date.after_or_equal'  => 'Datum obnovy nesmí být v minulosti.',
            'effective_date.before_or_equal' => 'Datum obnovy max 60 dní dopředu (jinak hrozí kolize s automatickým strhnutím).',
        ]);

        $effective = $validated['effective_date'] ?? $today;
        $prevDay   = \Carbon\Carbon::parse($effective)->subDay()->toDateString();

        $activeInterrupt = DB::table('members_fees as mf')
            ->join('fees as f', 'f.id', '=', 'mf.fee_id')
            ->where('mf.member_id', $id)
            ->where('f.special_type_id', 1)
            ->where('mf.activation_date', '<=', $today)
            ->where('mf.deactivation_date', '>=', $today)
            ->select('mf.id as mf_id', 'mf.deactivation_date')
            ->first();

        if (!$activeInterrupt) {
            return back()->with('error', 'Tento člen nemá aktivní přerušení členství.');
        }

        $chargeResult = 'noop';
        DB::transaction(function () use ($id, $member, $activeInterrupt, $effective, $prevDay, &$chargeResult) {
            DB::table('members')->where('id', $id)->update([
                'leaving_date'          => '9999-12-31',
                'locked'                => 0,
                'payment_blocked'       => 0,
                'payment_blocked_since' => null,
                'pending_termination'   => 0,
            ]);

            DB::table('members_fees')
                ->where('id', $activeInterrupt->mf_id)
                ->update(['deactivation_date' => $prevDay]);

            DB::table('membership_interrupts')
                ->where('members_fee_id', $activeInterrupt->mf_id)
                ->update(['end_after_interrupt_end' => 0]);

            $ipIds = DB::table('ip_addresses as ip')
                ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                ->join('devices as d', 'd.id', '=', 'i.device_id')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->where('u.member_id', $id)
                ->pluck('ip.id');
            if ($ipIds->isNotEmpty()) {
                DB::table('messages_ip_addresses')->whereIn('ip_address_id', $ipIds)->delete();
            }

            $chargeResult = $this->chargeMonthlyFeeNow($id, (int) $member->type, $effective);
        });

        $when = $effective === now()->toDateString() ? 'dnes' : 'k ' . $effective;
        if ($chargeResult === 'skipped_blocked') {
            return redirect()->route('members.show', $id)
                ->with('success', "Členství obnoveno {$when}. Poplatek NEstrhnut — nedostatek kreditu, nastaven payment_blocked. Doplatek proběhne po další příchozí platbě.");
        }
        if ($chargeResult === 'noop') {
            return redirect()->route('members.show', $id)
                ->with('success', "Členství obnoveno {$when}. Poplatek nestrhnut (nulový tarif nebo už existuje), přesměrování smazáno.");
        }
        return redirect()->route('members.show', $id)
            ->with('success', "Členství obnoveno {$when}. Poplatek strhnut k {$effective}, přesměrování smazáno.");
    }

    /**
     * Efektivní měsíční poplatek + jeho zdroj pro zobrazení na kartě člena.
     * Vrací [?float $amount, ?string $source] kde source ∈ {individuální|tarif|základní}.
     * Pořadí drží App\Services\RegularFeeResolver (shodné se srážkou).
     */
    private function resolveMonthlyFee(int $memberId, int $memberType): array
    {
        $date = now()->toDateString();
        $individual = RegularFeeResolver::individualFee($memberId, $date);
        if ($individual !== null) return [$individual, 'individuální'];
        $tarif = RegularFeeResolver::tarifPrice($memberId);
        if ($tarif !== null) return [$tarif, 'tarif'];
        $default = RegularFeeResolver::defaultFeeByType($memberType);
        if ($default !== null) return [$default, 'základní'];
        return [null, null];
    }

    /**
     * Strhne 1× měsíční poplatek aktuálního dne — používá restoreInterrupt po
     * obnově přerušeného členství. Prepaid pravidlo (jako DeductFees): při
     * nedostatku kreditu transfer nevytvořit, payment_blocked=1 + datum;
     * doplatek doběhne PaymentBackchargeService po další příchozí platbě.
     * Idempotence: kontrola existujícího transfer.type=1 + datetime.
     *
     * Vrací: 'charged' | 'skipped_blocked' | 'noop'
     */
    private function chargeMonthlyFeeNow(int $memberId, int $memberType, string $date): string
    {
        // Pořadí individuální → cena tarifu → základní řeší centrální resolver.
        $feeAmount = (float) (RegularFeeResolver::amount($memberId, $memberType, $date) ?? 0.0);

        if ($feeAmount <= 0) return 'noop';

        $creditAccount = DB::table('accounts')
            ->where('member_id', $memberId)
            ->where('account_attribute_id', 221100)
            ->first(['id', 'balance']);
        if (!$creditAccount) return 'noop';

        $orgAccount = DB::table('accounts')
            ->where('account_attribute_id', 684000)
            ->value('id');
        if (!$orgAccount) return 'noop';

        $alreadyCharged = DB::table('transfers')
            ->where('origin_id', $creditAccount->id)
            ->where('type', 1)
            ->where('datetime', $date)
            ->exists();
        if ($alreadyCharged) return 'noop';

        // Prepaid: nedost. kredit → nestrhávat, jen flag (PaymentBackchargeService doběhne)
        if ((float) $creditAccount->balance < $feeAmount) {
            DB::table('members')->where('id', $memberId)->update([
                'payment_blocked'       => 1,
                'payment_blocked_since' => DB::raw('COALESCE(payment_blocked_since, ' . DB::getPdo()->quote($date) . ')'),
            ]);
            return 'skipped_blocked';
        }

        DB::table('transfers')->insert([
            'origin_id'         => $creditAccount->id,
            'destination_id'    => $orgAccount,
            'type'              => 1,
            'amount'            => $feeAmount,
            'datetime'          => $date,
            'creation_datetime' => now()->format('Y-m-d H:i:s'),
            'text'              => 'Doplatek za měsíc po obnovení přerušení',
            'member_id'         => null,
            'user_id'           => auth()->id(),
        ]);
        DB::table('accounts')->where('id', $creditAccount->id)->decrement('balance', $feeAmount);
        return 'charged';
    }

    public function approve(int $id)
    {
        abort_unless($this->aclCheck('edit_all', 'Members_Controller', 'members'), 403);

        $member = Member::findOrFail($id);

        if (!in_array($member->type, [MemberType::PENDING_MEMBER, MemberType::PENDING_CUSTOMER])) {
            return back()->with('error', 'Člen není čekatel.');
        }

        if (!$member->registration) {
            return back()->with('error', 'Přihláška/smlouva není podepsána.');
        }

        $messageId = ($member->type == MemberType::PENDING_MEMBER) ? 10 : 116;

        $member->type = ($member->type == MemberType::PENDING_MEMBER)
            ? MemberType::REGULAR
            : MemberType::CUSTOMER;
        $member->entrance_form_accepted = now()->toDateString();
        $member->save();

        $message = \App\Models\Message::where('id', $messageId)->first();
        if ($message && $message->email_text) {
            $userId = DB::table('users')->where('member_id', $id)->value('id');
            if ($userId) {
                $email = DB::table('contacts as c')
                    ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                    ->where('uc.user_id', $userId)
                    ->where('c.type', 20)
                    ->value('c.value');

                if ($email) {
                    $body = \App\Models\Message::substitute(
                        $message->email_text,
                        \App\Models\Message::buildPlaceholders($id)
                    );
                    $prefix = \App\Models\Setting::get('email_subject_prefix', '');
                    DB::table('email_queues')->insert([
                        'from'    => \App\Models\Setting::get('email_default_email', 'noreply@pvfree.net'),
                        'to'      => $email,
                        'subject' => ($prefix ? $prefix . ' :: ' : '') . $message->name,
                        'body'    => $body,
                        'state'   => 0,
                    ]);
                }
            }
        }

        return redirect()->route('members.show', $id)
            ->with('success', 'Člen byl schválen a byl mu odeslán email.');
    }

    /**
     * Export přihlášky nebo ukončení členství jako PDF (inline v prohlížeči).
     * type: 'registration' = Přihláška, 'end' = Ukončení členství, 'contract_end' = Výpověď smlouvy
     */
    public function registrationExport(int $id, string $type)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'registration_export'), 403);
        abort_unless(in_array($type, ['registration', 'end', 'contract_end']), 404);

        [$pdfString, $filename] = $this->buildRegistrationPdf($id, $type);

        return response($pdfString, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Odeslat stejné PDF (přihláška / ukončení / výpověď) e-mailem na první
     * e-mail kontakt hlavního uživatele. Stejný ACL gate jako inline export.
     */
    public function registrationExportEmail(int $id, string $type)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'registration_export'), 403);
        abort_unless(in_array($type, ['registration', 'end', 'contract_end']), 404);

        $mainUser = DB::table('users')
            ->where('member_id', $id)
            ->where('type', 1) // MAIN_USER
            ->first();
        abort_if(!$mainUser, 404);

        $email = DB::table('contacts as c')
            ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
            ->where('uc.user_id', $mainUser->id)
            ->where('c.type', 20) // TYPE_EMAIL
            ->orderBy('c.id')
            ->value('c.value');

        if (!$email) {
            return back()->with('error', 'Člen nemá v kontaktech žádný e-mail.');
        }

        [$pdfString, $filename] = $this->buildRegistrationPdf($id, $type);

        // Storage dir je v allowed-list SendEmailQueue (storage/...). Soubor zůstává
        // i po odeslání — admin akce, není to bulk, případný cleanup řešíme později.
        $dir = storage_path('app/email-attachments');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $storedName = preg_replace('/\.pdf$/', '', $filename) . '-' . uniqid() . '.pdf';
        $path = $dir . DIRECTORY_SEPARATOR . $storedName;
        file_put_contents($path, $pdfString);

        $subject = match($type) {
            'registration'  => 'Přihláška za člena',
            'end'           => 'Ukončení členství',
            'contract_end'  => 'Výpověď smlouvy',
            default         => 'Dokument',
        };

        $assocName = \App\Models\Setting::get('title', 'Sdružení');

        $emailQueue = EmailQueue::create([
            'from'        => \App\Models\Setting::get('email_default_email', 'noreply@pvfree.net'),
            'to'          => $email,
            'subject'     => $subject,
            'body'        => "<p>Dobrý den,</p>"
                . "<p>v příloze posíláme dokument <strong>" . e($subject) . "</strong> ve formátu PDF.</p>"
                . "<p>S pozdravem,<br>" . e($assocName) . "</p>",
            'state'       => EmailQueue::STATE_NEW,
            'access_time' => now(),
        ]);

        EmailQueueAttachment::create([
            'email_queue_id' => $emailQueue->id,
            'path'           => $path,
            'name'           => $filename,
            'mime'           => 'application/pdf',
            'created_at'     => now(),
        ]);

        return back()->with('success', 'PDF „' . $subject . '" zařazeno do fronty k odeslání na ' . $email . '.');
    }

    /**
     * Vygeneruje PDF (registrační/ukončovací/výpovědní) a vrátí [bytes, filename].
     * Sdíleno mezi inline exportem a odesláním e-mailem.
     */
    private function buildRegistrationPdf(int $id, string $type): array
    {
        $member = DB::table('members as m')
            ->join('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->join('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->where('m.id', $id)
            ->select('m.*', 't.town', 't.zip_code', 's.street', 'ap.street_number')
            ->first();
        abort_if(!$member, 404);

        // Sdružení (člen id=1)
        $assoc = DB::table('members as m')
            ->join('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->join('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->where('m.id', 1)
            ->select('m.*', 't.town', 't.zip_code', 's.street', 'ap.street_number')
            ->first();

        // Hlavní uživatel
        $mainUser = DB::table('users')
            ->where('member_id', $id)
            ->where('type', 1) // MAIN_USER
            ->first();

        // Kontakty (email=20, telefon=21) hlavního uživatele
        $contacts = $mainUser
            ? DB::table('contacts as c')
                ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                ->where('uc.user_id', $mainUser->id)
                ->whereIn('c.type', [20, 21])
                ->select('c.type', 'c.value')
                ->get()
            : collect();

        $email = $contacts->firstWhere('type', 20)?->value ?? '';
        $phone = $contacts->firstWhere('type', 21)?->value ?? '';

        // ICQ / Jabber / Skype / MSN kontakty (typy 18,19,22,23)
        $otherContacts = $mainUser
            ? DB::table('contacts as c')
                ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                ->join('enum_types as et', 'et.id', '=', 'c.type')
                ->where('uc.user_id', $mainUser->id)
                ->whereIn('c.type', [18, 19, 22, 23])
                ->selectRaw('et.value as type_name, c.value')
                ->get()
            : collect();

        // Variabilní symboly
        $variableSymbols = DB::table('variable_symbols as vs')
            ->join('accounts as a', 'a.id', '=', 'vs.account_id')
            ->where('a.member_id', $id)
            ->pluck('vs.variable_symbol');

        // Kredit (zůstatek kreditního účtu)
        $creditBalance = DB::table('accounts')
            ->where('member_id', $id)
            ->where('account_attribute_id', 221100)
            ->value('balance') ?? 0;

        // Podsíť (první subnet přes zařízení člena)
        $subnetName = DB::table('users as u')
            ->join('devices as d', 'd.user_id', '=', 'u.id')
            ->join('ifaces as i', 'i.device_id', '=', 'd.id')
            ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->join('subnets as s', 's.id', '=', 'ip.subnet_id')
            ->where('u.member_id', $id)
            ->where('u.type', 1)
            ->value('s.name') ?? '';

        // Technici zařízení člena
        $engineers = DB::table('device_engineers as de')
            ->join('devices as d', 'd.id', '=', 'de.device_id')
            ->join('users as du', 'du.id', '=', 'd.user_id')
            ->join('users as u', 'u.id', '=', 'de.user_id')
            ->where('du.member_id', $id)
            ->selectRaw('CONCAT(u.name, " ", u.surname) as name')
            ->distinct()
            ->pluck('name');

        // Bankovní účet sdružení
        $bankAccountId = (int) \App\Models\Setting::get('export_header_bank_account', 1);
        $bankAccount = DB::table('bank_accounts')->where('id', $bankAccountId)->first();

        // Konfigurace
        $registrationInfo    = \App\Models\Setting::get('registration_info', '');
        $registrationLicense = \App\Models\Setting::get('registration_license', '');

        // Kontaktní údaje sdružení pro contract_end footer
        $assocWww      = \App\Models\Setting::get('association_www', '');
        $assocEmail    = \App\Models\Setting::get('association_email', '');
        $assocPhone    = \App\Models\Setting::get('association_phone', '');
        $assocCourt    = \App\Models\Setting::get('association_court', '');
        $assocCourtRef = \App\Models\Setting::get('association_court_ref', '');

        $viewName = ($type === 'contract_end')
            ? 'members.contract_end_pdf'
            : 'members.registration_pdf';

        $html = view($viewName, compact(
            'type', 'member', 'assoc', 'mainUser',
            'email', 'phone', 'variableSymbols', 'bankAccount',
            'registrationInfo', 'registrationLicense',
            'assocWww', 'assocEmail', 'assocPhone', 'assocCourt', 'assocCourtRef',
            'otherContacts', 'creditBalance', 'subnetName', 'engineers'
        ))->render();

        $tmpDir = storage_path('framework/cache/mpdf');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'tempDir'           => $tmpDir,
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 20,
            'margin_bottom'     => 20,
            'margin_left'       => 15,
            'margin_right'      => 15,
            'default_font'      => 'dejavusans',
            'default_font_size' => 9,
        ]);
        $mpdf->WriteHTML($html);
        $pdfString = $mpdf->Output('', 'S');

        $filePrefix = match($type) {
            'registration'  => 'prihlaska',
            'end'           => 'ukonceni-clenstvi',
            'contract_end'  => 'vypoved-smlouvy',
            default         => 'export',
        };
        $filename = $filePrefix . '-' . $id . '.pdf';

        return [$pdfString, $filename];
    }

    /**
     * Samoobslužná stránka — uživatel si sám spravuje, kterými kanály chce
     * dostávat oznámení. Vyžaduje jen auth, žádné ACL.
     */
    public function myNotificationsForm()
    {
        $memberId = (int) (auth()->user()->member_id ?? 0);
        $member   = $memberId ? Member::find($memberId) : null;
        abort_if(!$member, 404, 'Účet není navázán na člena.');

        return view('account.notifications', ['member' => $member]);
    }

    public function myNotificationsUpdate(Request $request)
    {
        $memberId = (int) (auth()->user()->member_id ?? 0);
        $member   = $memberId ? Member::find($memberId) : null;
        abort_if(!$member, 404, 'Účet není navázán na člena.');

        $member->update([
            'notification_by_redirection' => $request->boolean('notification_by_redirection'),
            'notification_by_email'       => $request->boolean('notification_by_email'),
            'notification_by_sms'         => $request->boolean('notification_by_sms'),
        ]);

        return redirect()->route('me.notifications')
            ->with('success', 'Nastavení oznámení bylo uloženo.');
    }
}
