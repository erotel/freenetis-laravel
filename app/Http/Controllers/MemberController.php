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
        if (!$this->can('new_all')) {
            abort(403);
        }

        $types = MemberType::labels();
        unset($types[MemberType::FORMER], $types[MemberType::APPLICANT]);

        $towns   = Town::orderBy('town')->get();
        $streets = Street::orderBy('street')->get();

        return view('members.create', compact('types', 'towns', 'streets'));
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'type'           => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date'  => 'nullable|date',
            'comment'        => 'nullable|string|max:250',
            'organization_identifier'     => 'nullable|string|max:20',
            'vat_organization_identifier' => 'nullable|string|max:30',
            'town_id'         => 'nullable|integer|exists:towns,id',
            'street_id'       => 'nullable|integer|exists:streets,id',
            'street_number'   => 'nullable|string|max:50',
        ]);

        $member = Member::create(array_merge($data, [
            'locked'       => $request->boolean('locked'),
            'registration' => $request->boolean('registration'),
        ]));

        if ($request->filled('town_id')) {
            $addressPoint = new AddressPoint();
            $addressPoint->town_id       = $data['town_id'];
            $addressPoint->street_id     = $data['street_id'] ?? null;
            $addressPoint->street_number = $data['street_number'] ?? null;
            $addressPoint->country_id    = 1;
            $addressPoint->save();
            $member->address_point_id = $addressPoint->id;
            $member->save();
        }

        session()->flash('success', 'Člen byl úspěšně přidán.');

        return redirect()->route('members.show', $member->id);
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
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $member = Member::find($id);
        if (!$member) {
            abort(404);
        }

        if ($member->users()->exists()) {
            session()->flash('error', 'Člena nelze smazat, má přiřazené uživatele.');
            return redirect()->back();
        }

        $member->delete();

        session()->flash('success', 'Člen byl úspěšně smazán.');

        return redirect()->route('members.index');
    }
}
