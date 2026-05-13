<?php

namespace App\Http\Controllers;

use App\Helpers\MemberType;
use App\Models\AccountAttribute;
use App\Models\AddressPoint;
use App\Http\Filters\MemberFilter;
use App\Models\IpAddress;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Street;
use App\Models\Town;
use App\Services\ContractService;
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
                DB::raw('(SELECT a.balance FROM accounts a WHERE a.member_id = members.id AND a.account_attribute_id = 221100 LIMIT 1) AS credit_balance'),
                DB::raw('(SELECT msg.type FROM messages_ip_addresses mia JOIN ip_addresses ip ON ip.id = mia.ip_address_id JOIN messages msg ON msg.id = mia.message_id WHERE ip.member_id = members.id LIMIT 1) AS redirect_type'),
                DB::raw("(SELECT CASE WHEN mw.permanent = 1 OR mw.until = '9999-12-31' THEN 'permanent' ELSE 'temporary' END FROM members_whitelists mw WHERE mw.member_id = members.id AND mw.since <= CURDATE() AND mw.until >= CURDATE() LIMIT 1) AS whitelist_type"),
            ])
            ->with(['addressPoint.town', 'addressPoint.street']);

        if ($search !== '') {
            $query->where('members.name', 'like', "%{$search}%");
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

        return view('members.show', [
            'member'              => $member,
            'variableSymbols'     => $variableSymbols,
            'creditAccount'       => $creditAccount,
            'activeMemberFee'     => $activeMemberFee,
            'tvEnabled'           => (bool) \App\Models\Setting::get('sledovanitv_enabled', 0),
            'canEdit'             => $this->can('edit_all'),
            'canDelete'           => $this->can('delete_all'),
            'mainUser'            => $mainUser,
            'contacts'            => $contacts,
            'canViewUser'         => $this->aclCheck('view_all', 'Users_Controller', 'users'),
            'canEditUser'         => $this->aclCheck('edit_all', 'Users_Controller', 'users'),
            'canViewContacts'     => $this->aclCheck('view_all', 'Users_Controller', 'additional_contacts'),
            'canViewTransfers'    => $this->aclCheck('view_all', 'Accounts_Controller', 'transfers'),
            'canViewIpAddresses'  => $this->aclCheck('view_all', 'Ip_addresses_Controller', 'ip_address'),
            'canViewDevices'      => $this->aclCheck('view_all', 'Devices_Controller', 'devices'),
            'canViewFees'         => $this->aclCheck('view_all', 'Members_Controller', 'fees'),
            'canViewQos'           => $this->aclCheck('view_all', 'Members_Controller', 'qos_ceil'),
            'canViewAllowedSubnets'=> $this->aclCheck('view_all', 'Allowed_subnets_Controller', 'allowed_subnet'),
            'canViewInvoices'      => $isOwnProfile || $this->aclCheck('view_all', 'Accounts_Controller', 'invoices'),
            'canNotify'            => $this->aclCheck('new_all', 'Notifications_Controller', 'member'),
            'canExportRegistration'=> $this->aclCheck('view_all', 'Members_Controller', 'registration_export'),
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
            'street_number'               => 'required|string|max:50',
            'organization_identifier'     => 'nullable|string|max:20',
            'vat_organization_identifier' => 'nullable|string|max:30',
            'birthday'                    => 'required|date',
            'comment'                     => 'nullable|string|max:250',
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
            'organization_identifier'     => 'nullable|string|max:20',
            'vat_organization_identifier' => 'nullable|string|max:30',
            'town_id'         => 'nullable|integer|exists:towns,id',
            'street_id'       => 'nullable|integer|exists:streets,id',
            'street_number'   => 'nullable|string|max:50',
            'speed_class_id'         => 'nullable|integer|exists:speed_classes,id',
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
            return redirect()->route('members.index')
                ->with('success', 'Čekající člen byl smazán.');
        }

        // Krok 1: pokud není ještě bývalý, označit jako bývalého
        if (!in_array($member->type, $formerTypes)) {
            // typ 90 (řádný člen) → 16 (bývalý zákazník), ostatní → 15 (bývalý člen)
            $newType = ($member->type == MemberType::REGULAR) ? MemberType::FORMER_CUSTOMER : MemberType::FORMER;
            DB::table('members')->where('id', $id)->update([
                'type'         => $newType,
                'locked'       => 1,
                'leaving_date' => now()->format('Y-m-d'),
            ]);
            $label = ($newType === MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
            return redirect()->route('members.show', $id)
                ->with('info', "Člen byl označen jako bývalý {$label}. Pro úplné smazání klikněte znovu na Trvale smazat.");
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

        return redirect()->route('members.index')
            ->with('success', 'Člen a všechna jeho data byla trvale smazána.');
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

        $bankAccount = DB::table('bank_accounts')
            ->where('member_id', $id)
            ->first(['account_nr', 'bank_nr']);

        $refundAccount = $bankAccount
            ? trim($bankAccount->account_nr) . '/' . trim($bankAccount->bank_nr)
            : '';

        return view('members.end_membership', [
            'member'        => $member,
            'balance'       => $account?->balance ?? 0,
            'refundAccount' => $refundAccount,
            'today'         => now()->format('Y-m-d'),
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

        DB::transaction(function () use ($id, $member, $newType, $validated, $endMode) {
            DB::table('members')->where('id', $id)->update([
                'type'         => $newType,
                'locked'       => 1,
                'leaving_date' => $validated['leaving_date'],
            ]);

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
                            $emailQueueId = DB::table('email_queues')->insertGetId([
                                'from'    => \App\Models\Setting::get('email_default_email', 'noreply@pvfree.net'),
                                'to'      => $email,
                                'subject' => \App\Models\Setting::get('email_subject_prefix', 'PVfree.net') . ' :: ' . $message->name,
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

        $label = ($newType == MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
        return redirect()->route('members.show', $id)
            ->with('success', "Členství bylo ukončeno. {$member->name} označen jako bývalý {$label}.");
    }

    /**
     * Vrátí další číslo dobropisu (refund doc_number) v aktuálním roce.
     *  - Zákazník: "YY###"     (např. 26011 = rok 26, sekvence 011)
     *  - Člen:     "YYČL####"  (např. 26ČL0010)
     * Sekvence je per-rok + per-typ; reset každý kalendářní rok.
     * RIGHT() místo SUBSTRING() ať MySQL korektně rozezná multibyte 'Č' v "ČL".
     */
    private function nextRefundDocNumber(bool $isCustomer): string
    {
        $year2 = date('y');
        if ($isCustomer) {
            $maxSeq = (int) DB::table('pohoda_refund_queue')
                ->where('member_type', MemberType::CUSTOMER)
                ->where('doc_number', 'like', $year2 . '%')
                ->where('doc_number', 'not like', $year2 . 'ČL%')
                ->selectRaw('MAX(CAST(RIGHT(doc_number, 3) AS UNSIGNED)) AS m')
                ->value('m');
            return $year2 . str_pad((string) ($maxSeq + 1), 3, '0', STR_PAD_LEFT);
        }

        $prefix = $year2 . 'ČL';
        $maxSeq = (int) DB::table('pohoda_refund_queue')
            ->where('member_type', MemberType::REGULAR)
            ->where('doc_number', 'like', $prefix . '%')
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

        DB::table('members')->where('id', $id)->update([
            'type'         => $originalType,
            'locked'       => 0,
            'leaving_date' => '9999-12-31',
        ]);

        $label = ($originalType == MemberType::REGULAR) ? 'zákazník' : 'člen';
        return redirect()->route('members.show', $id)
            ->with('success', "Člen byl obnoven jako {$label}.");
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
                    DB::table('email_queues')->insert([
                        'from'    => \App\Models\Setting::get('email_default_email', 'noreply@pvfree.net'),
                        'to'      => $email,
                        'subject' => \App\Models\Setting::get('email_subject_prefix', 'PVfree.net') . ' :: ' . $message->name,
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
     * type: 'registration' = Přihláška, 'end' = Ukončení členství
     */
    public function registrationExport(int $id, string $type)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'registration_export'), 403);
        abort_unless(in_array($type, ['registration', 'end', 'contract_end']), 404);

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

        return response($pdfString, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
