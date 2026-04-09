<?php

namespace App\Http\Controllers;

use App\Helpers\MemberType;
use App\Models\AccountAttribute;
use App\Models\AddressPoint;
use App\Models\Member;
use App\Models\MemberFee;
use App\Models\Street;
use App\Models\Town;
use App\Services\AclService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MemberController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_VALUE   = 'members';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index(Request $request)
    {
        if (!$this->can('view_all')) {
            abort(403);
        }

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
        $currentType   = $request->query('type', 'all');
        $currentLocked = $request->query('locked', 'all');

        // Paginated list with first variable symbol via subquery
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
                DB::raw('(SELECT vs.variable_symbol FROM accounts a JOIN variable_symbols vs ON vs.account_id = a.id WHERE a.member_id = members.id ORDER BY vs.id LIMIT 1) AS variable_symbol'),
            ])
            ->with('addressPoint.town');

        if ($search !== '') {
            $query->where('members.name', 'like', "%{$search}%");
        }
        if ($currentType !== 'all') {
            $query->where('members.type', (int) $currentType);
        }
        if ($currentLocked !== 'all') {
            $query->where('members.locked', (int) $currentLocked);
        }

        $members = $query->orderBy('members.' . $sort, $dir)
            ->paginate($perPage)
            ->withQueryString();

        return view('members.index', [
            'members'       => $members,
            'sort'          => $sort,
            'dir'           => $dir,
            'perPage'       => $perPage,
            'search'        => $search,
            'memberTypes'   => MemberType::labels(),
            'currentType'   => $currentType,
            'currentLocked' => $currentLocked,
            'canNew'        => $this->can('new_all'),
            'canEdit'       => $this->can('edit_all'),
            'canDelete'     => $this->can('delete_all'),
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
            'canEdit'             => $this->can('edit_all'),
            'canDelete'           => $this->can('delete_all'),
            'mainUser'            => $mainUser,
            'contacts'            => $contacts,
            'canViewUser'         => $this->acl->hasAccess(auth()->id(), 'view_all', 'Users_Controller', 'users'),
            'canEditUser'         => $this->acl->hasAccess(auth()->id(), 'edit_all', 'Users_Controller', 'users'),
            'canViewContacts'     => $this->acl->hasAccess(auth()->id(), 'view_all', 'Users_Controller', 'additional_contacts'),
            'canViewTransfers'    => $this->acl->hasAccess(auth()->id(), 'view_all', 'Accounts_Controller', 'transfers'),
            'canViewIpAddresses'  => $this->acl->hasAccess(auth()->id(), 'view_all', 'Ip_addresses_Controller', 'ip_address'),
            'canViewDevices'      => $this->acl->hasAccess(auth()->id(), 'view_all', 'Devices_Controller', 'devices'),
            'canViewFees'         => $this->acl->hasAccess(auth()->id(), 'view_all', 'Members_Controller', 'fees'),
            'canViewQos'           => $this->acl->hasAccess(auth()->id(), 'view_all', 'Members_Controller', 'qos_ceil'),
            'canViewAllowedSubnets'=> $this->acl->hasAccess(auth()->id(), 'view_all', 'Allowed_subnets_Controller', 'allowed_subnet'),
            'canViewInvoices'      => $isOwnProfile || $this->acl->hasAccess(auth()->id(), 'view_all', 'Accounts_Controller', 'invoices'),
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
            'surname'                     => 'required|string|max:60',
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
            $fullName = trim($request->name . ' ' . $request->surname);
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
                'surname'              => $request->surname,
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
        $canEditQos        = $this->acl->hasAccess(auth()->id(), 'edit_all', 'Members_Controller', 'qos_ceil');

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
            'leaving_date'                => $request->leaving_date ?: '0000-00-00',
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

        session()->flash('success', 'Člen byl úspěšně upraven.');

        return redirect()->route('members.show', $id);
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $member = DB::table('members')->where('id', $id)->first();
        abort_if(!$member, 404);

        $formerTypes = [MemberType::FORMER, MemberType::FORMER_CUSTOMER];

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
}
