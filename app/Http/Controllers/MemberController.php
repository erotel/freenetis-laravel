<?php

namespace App\Http\Controllers;

use App\Domains\Members\Actions\MarkMemberFormerAction;
use App\Domains\Members\Actions\PurgeMemberAction;
use App\Domains\Members\Actions\RegisterMemberAction;
use App\Domains\Members\Actions\UpdateMemberAction;
use App\Domains\Members\Queries\MemberCardQuery;
use App\Domains\Members\Queries\MemberListQuery;
use App\Domains\Members\Repositories\AddressBookRepository;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Network\Repositories\SpeedClassRepository;
use App\Helpers\MemberType;
use App\Http\Filters\MemberFilter;
use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Models\Setting;
use Illuminate\Http\Request;

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

    public function index(Request $request, MemberListQuery $list)
    {
        $sort = in_array($request->query('sort'), MemberListQuery::SORTS, true) ? $request->query('sort') : 'id';
        $dir  = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $perPage = (int) $request->query('record_per_page', 50);
        if (!in_array($perPage, MemberListQuery::PER_PAGES, true)) {
            $perPage = 50;
        }

        $search        = trim((string) $request->query('search', ''));
        $currentLocked = $request->query('locked', 'all');

        // types — čárkou oddělený seznam typů (např. "2,16,18") nebo 'all'
        $typesParam   = $request->query('types', 'all');
        $currentTypes = 'all';
        $typesArray   = [];
        if ($typesParam !== 'all' && $typesParam !== '') {
            $typesArray = array_filter(array_map('intval', explode(',', $typesParam)), fn ($t) => $t > 0);
            if (!empty($typesArray)) {
                $currentTypes = implode(',', $typesArray);
            }
        }

        $advancedFilters = $request->input('filters', []);
        $tvEnabled       = (bool) Setting::get('sledovanitv_enabled', 0);

        return view('members.index', [
            'members'         => $list->paginate($search, $typesArray, (string) $currentLocked, $advancedFilters, $sort, $dir, $perPage),
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

    public function show(int $id, MemberCardQuery $card)
    {
        $isOwnProfile = ($id == auth()->user()?->member_id);

        if (!$isOwnProfile && !$this->can('view_all')) {
            abort(403);
        }

        $member = $card->find($id);
        abort_if(!$member, 404);

        // Audit trail (NIS2/ZoKB) jen pro adminy (edit_all) — citlivé provozní údaje.
        // Zámek přihlášení uživatelů jen pro toho, kdo ho smí odemknout.
        $canViewAudit   = $this->can('edit_all');
        $canUnlockLogin = $this->aclCheck('edit_all', 'Users_Controller', 'users');

        return view('members.show', $card->data($member, $canViewAudit, $canUnlockLogin) + [
            'canViewAudit'        => $canViewAudit,
            'canUnlockLogin'      => $canUnlockLogin,
            'canEdit'             => $this->can('edit_all'),
            'canDelete'           => $this->can('delete_all'),
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
            'canExportRegistration'=> $this->aclCheck('view_all', 'Members_Controller', 'registration_export')
                || ($isOwnProfile && $this->aclCheck('view_own', 'Members_Controller', 'registration_export')),
            // Plný export (ukončení/výpověď + odeslání e-mailem) jen staff (view_all);
            // řádný člen na svém profilu (view_own) smí jen stáhnout vlastní přihlášku.
            'canExportFull'        => $this->aclCheck('view_all', 'Members_Controller', 'registration_export'),
            'canViewComment'       => $this->aclCheck('view_all',   'Members_Controller', 'comment'),
            'canComment'           => $this->aclCheck('new_all',    'Members_Controller', 'comment'),
            'canEditComment'       => $this->aclCheck('edit_all',   'Members_Controller', 'comment'),
            'canDeleteComment'     => $this->aclCheck('delete_all', 'Members_Controller', 'comment'),
            'canViewInterrupts'    => $this->aclCheck('view_all', 'Members_Controller', 'membership_interrupts'),
            'canEditInterrupts'    => $this->aclCheck('edit_all', 'Members_Controller', 'membership_interrupts'),
            'canViewWhitelists'    => $this->aclCheck('view_all', 'Members_whitelists_Controller', 'whitelist'),
            'canViewLanAccess'     => $this->aclCheck('view_all', 'Members_Controller', 'lan_access'),
            'canEditLanAccess'     => $this->aclCheck('edit_all', 'Members_Controller', 'lan_access'),
            'canViewRedirect'      => $this->aclCheck('view_all',   'Redirect_Controller', 'redirect'),
            'canEditRedirect'      => $this->aclCheck('edit_all',   'Redirect_Controller', 'redirect'),
            'canDeleteRedirect'    => $this->aclCheck('delete_all', 'Redirect_Controller', 'redirect'),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);

        $memberTypes = MemberType::labels();
        unset($memberTypes[MemberType::FORMER], $memberTypes[MemberType::APPLICANT]);

        $towns = app(AddressBookRepository::class)->townsByName();

        return view('members.create', compact('memberTypes', 'towns'));
    }

    public function store(StoreMemberRequest $request, RegisterMemberAction $register)
    {
        $memberId = $register->handle($request->toNewMember());

        return redirect()->route('members.show', $memberId)
            ->with('success', 'Člen byl úspěšně vytvořen.');
    }

    public function edit(int $id)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $member = app(MemberRepository::class)->findOrFail($id);
        $member->load('addressPoint.town', 'addressPoint.street');

        $types   = MemberType::labels();
        $towns   = app(AddressBookRepository::class)->townsByName();
        $streets = app(AddressBookRepository::class)->streetsByName();

        $speedClasses      = app(SpeedClassRepository::class)->allByName();
        $defaultSpeedClass = app(SpeedClassRepository::class)->memberDefault();
        $canEditQos        = $this->aclCheck('edit_all', 'Members_Controller', 'qos_ceil');
        $canEditVoting     = $this->aclCheck('edit_all', 'Meetings_Controller', 'meeting');

        // Má-li člen podepsanou smlouvu, tarif se mění POUZE dodatkem (apply-on-sign),
        // ne přímou editací — jinak by dostal novou rychlost/cenu bez podpisu.
        $hasSignedContract = app(UpdateMemberAction::class)->hasSignedContract($id);

        return view('members.edit', compact('member', 'types', 'towns', 'streets', 'speedClasses', 'defaultSpeedClass', 'canEditQos', 'canEditVoting', 'hasSignedContract'));
    }

    public function update(UpdateMemberRequest $request, int $id, UpdateMemberAction $update)
    {
        // Jemná práva vynucuje i server (formulář pole jen skrývá): třídu rychlosti
        // jen s qos_ceil, hlasovací právo jen s právem na modul Schůzí.
        $update->handle(
            $id,
            $request->toChanges(),
            $this->aclCheck('edit_all', 'Members_Controller', 'qos_ceil'),
            $this->aclCheck('edit_all', 'Meetings_Controller', 'meeting'),
            auth()->id(),
        );

        session()->flash('success', 'Člen byl úspěšně upraven.');

        return redirect()->route('members.show', $id);
    }

    /**
     * Trvale smazat: čekatel (17/18) se smaže hned; aktivní člen se v kroku 1
     * označí jako bývalý (90→15, jinak 16); bývalého (15/16) smaže krok 2.
     */
    public function destroy(int $id, PurgeMemberAction $purge, MarkMemberFormerAction $markFormer)
    {
        abort_unless($this->can('delete_all'), 403);

        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);

        if (PurgeMemberAction::isPurgeable((int) $member->type)) {
            $purge->handle($member);
            $note = in_array((int) $member->type, PurgeMemberAction::PENDING_TYPES, true)
                ? 'Čekající člen byl smazán.'
                : 'Člen a všechna jeho data byla trvale smazána.';
            return redirect()->route('members.index')->with('success', $note);
        }

        $ended = $markFormer->handle($member, auth()->user()?->login);
        $label = ($ended->formerType === MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
        $note  = "Člen byl označen jako bývalý {$label}. Pro úplné smazání klikněte znovu na Trvale smazat.";
        if ($ended->contractsTerminated > 0) {
            $note .= ' Smlouva byla označena jako ukončená.';
        }
        return redirect()->route('members.show', $id)->with('info', $note);
    }

}
