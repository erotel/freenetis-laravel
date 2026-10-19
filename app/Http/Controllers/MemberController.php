<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Actions\ChargeMonthlyFeeNowAction;
use App\Domains\Billing\Fees\FeePolicy;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Repositories\RefundRepository;
use App\Domains\Billing\Support\TransferType;
use App\Domains\Members\Actions\ApproveMemberAction;
use App\Domains\Members\Actions\EmailRegistrationDocumentAction;
use App\Domains\Members\Actions\EndMembershipAction;
use App\Domains\Members\Actions\MarkMemberFormerAction;
use App\Domains\Members\Actions\PurgeMemberAction;
use App\Domains\Members\Actions\RegisterMemberAction;
use App\Domains\Members\Actions\RestoreFormerMemberAction;
use App\Domains\Members\Actions\ResumeInterruptedMembershipAction;
use App\Domains\Members\Actions\UpdateMemberAction;
use App\Domains\Members\Exceptions\MemberNotApprovable;
use App\Domains\Members\Documents\RegistrationDocument;
use App\Domains\Members\Exceptions\MemberNotRestorable;
use App\Domains\Members\Exceptions\MembershipNotEndable;
use App\Domains\Members\Queries\MemberCardQuery;
use App\Domains\Members\Queries\MemberListQuery;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\EndMode;
use App\Domains\Members\Support\FormerType;
use App\Helpers\MemberType;
use App\Http\Filters\MemberFilter;
use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Domains\Members\Repositories\AddressBookRepository;
use App\Domains\Network\Repositories\SpeedClassRepository;
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

    public function applicants(MemberListQuery $list)
    {
        abort_unless($this->can('view_all'), 403);

        return view('members.applicants', ['pending' => $list->applicants()]);
    }

    /**
     * Seznam členů označených k ukončení (cron MarkPendingTermination, 14. den
     * každého měsíce — VOP: 1 měsíc neplacení = automatický konec smlouvy).
     * Admin každý případ projde a klikne 'Ukončit' (link na endMembershipForm
     * s předvyplněným leaving_date) nebo 'Reset blokace' pokud chce dát milost.
     */
    public function pendingTermination(MemberListQuery $list)
    {
        abort_unless($this->can('view_all'), 403);

        return view('members.pending_termination', [
            'rows'    => $list->pendingTermination(),
            'today'   => now()->format('Y-m-d'),
            'canEdit' => $this->can('edit_all'),
        ]);
    }

    /** Má výpověď s datem odchodu v budoucnu (běží výpovědní lhůta)? */
    public function endMembershipForm(int $id, RefundRepository $refunds, LedgerRepository $ledger, FeePolicy $fees)
    {
        abort_unless($this->can('edit_all'), 403);
        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);
        abort_if(in_array($member->type, [15, 16]), 400, 'Člen již byl ukončen.');
        abort_if(FormerType::inNoticePeriod($member->leaving_date), 400, "Člen už má výpověď s datem odchodu {$member->leaving_date}.");

        $account = $ledger->creditAccount($id);
        $today   = now()->toDateString();

        // Do data odchodu strhne srážka (fees:deduct) poplatek za každý deduct_day
        // před odchodem — JS z měsíčního poplatku přepočítá vratku podle data, ať
        // admin nevrátí peníze, které srážka stejně strhne. Měsíčně = pravidelný
        // poplatek (individuální → tarif → základní) + příplatky (služby, místa).
        $monthlyFee = (float) ($fees->regularFee($id, (int) $member->type, $today) ?? 0.0)
            + $fees->surcharges($id, $today);

        return view('members.end_membership', [
            'member'        => $member,
            'balance'       => $account?->balance ?? 0,
            'refundAccount' => $refunds->suggestedRefundAccount($id),
            'today'         => now()->format('Y-m-d'),
            'monthlyFee'    => $monthlyFee,
            'deductDay'     => (int) \App\Models\Setting::get('deduct_day', 26),
            // Dnešní srážka už proběhla → nepočítat ji mezi budoucí.
            'todayDeducted' => $account ? $ledger->hasTransferOnDay((int) $account->id, TransferType::MEMBER_FEE, $today) : false,
        ]);
    }

    public function endMembership(Request $request, int $id, EndMembershipAction $endMembership)
    {
        abort_unless($this->can('edit_all'), 403);

        $isRefundMode = (int) $request->input('end_mode') === EndMode::REFUND;

        $validated = $request->validate([
            'leaving_date'   => 'required|date',
            'end_mode'       => 'required|integer|in:' . implode(',', EndMode::ALL),
            'refund_account' => $isRefundMode ? 'required|string|max:50' : 'nullable',
            'refund_amount'  => $isRefundMode ? 'required|numeric|gt:0' : 'nullable',
        ]);

        try {
            $ended = $endMembership->handle(
                $id,
                (string) $validated['leaving_date'],
                (int) $validated['end_mode'],
                $validated['refund_account'] ?? null,
                isset($validated['refund_amount']) ? (float) $validated['refund_amount'] : null,
                auth()->id() ?? 1,
                auth()->user()?->login,
            );
        } catch (MembershipNotEndable $e) {
            abort_if($e->field === null, 400, $e->getMessage());
            return redirect()->back()->withInput()->withErrors([$e->field => $e->getMessage()]);
        }

        $name  = app(MemberRepository::class)->row($id)?->name;
        $label = ($ended->formerType == MemberType::FORMER_CUSTOMER) ? 'zákazník' : 'člen';
        $note  = "Členství bylo ukončeno. {$name} označen jako bývalý {$label}.";
        if ($ended->contractsTerminated > 0) {
            $note .= ' Smlouva byla označena jako ukončená.';
        }
        return redirect()->route('members.show', $id)->with('success', $note);
    }

    public function restore(int $id, RestoreFormerMemberAction $restore)
    {
        abort_unless($this->can('edit_all'), 403);

        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);

        try {
            $newType = $restore->handle($member);
        } catch (MemberNotRestorable $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = ($newType === MemberType::REGULAR) ? 'člen' : 'zákazník';
        return redirect()->route('members.show', $id)
            ->with('success', "Člen byl obnoven jako {$label}.");
    }

    /**
     * Obnovit přerušené členství (aktivní poplatek „Přerušení členství") k datu:
     * dnes, nebo max 60 dní dopředu (jinak hrozí kolize s automatickou srážkou).
     */
    public function restoreInterrupt(Request $request, int $id, ResumeInterruptedMembershipAction $resume)
    {
        abort_unless($this->can('edit_all'), 403);

        $member = app(MemberRepository::class)->row($id);
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

        try {
            $chargeResult = $resume->handle($member, $effective, auth()->id());
        } catch (MemberNotRestorable $e) {
            return back()->with('error', $e->getMessage());
        }

        $when = $effective === now()->toDateString() ? 'dnes' : 'k ' . $effective;
        if ($chargeResult === ChargeMonthlyFeeNowAction::SKIPPED_BLOCKED) {
            return redirect()->route('members.show', $id)
                ->with('success', "Členství obnoveno {$when}. Poplatek NEstrhnut — nedostatek kreditu, nastaven payment_blocked. Doplatek proběhne po další příchozí platbě.");
        }
        if ($chargeResult === ChargeMonthlyFeeNowAction::NOOP) {
            return redirect()->route('members.show', $id)
                ->with('success', "Členství obnoveno {$when}. Poplatek nestrhnut (nulový tarif nebo už existuje), přesměrování smazáno.");
        }
        return redirect()->route('members.show', $id)
            ->with('success', "Členství obnoveno {$when}. Poplatek strhnut k {$effective}, přesměrování smazáno.");
    }

    public function approve(int $id, ApproveMemberAction $approve)
    {
        abort_unless($this->aclCheck('edit_all', 'Members_Controller', 'members'), 403);

        try {
            $approve->handle($id);
        } catch (MemberNotApprovable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('members.show', $id)
            ->with('success', 'Člen byl schválen.');
    }

    /**
     * Export přihlášky nebo ukončení členství jako PDF (inline v prohlížeči).
     * type: 'registration' = Přihláška, 'end' = Ukončení členství, 'contract_end' = Výpověď smlouvy
     */
    public function registrationExport(int $id, string $type, RegistrationDocument $document)
    {
        abort_unless(in_array($type, RegistrationDocument::TYPES, true), 404);
        // Staff (view_all) → jakýkoli člen i typ. Řádný člen (view_own) → jen SVOJI
        // přihlášku (own + type=registration); ukončení/výpověď zůstává staff-only.
        $isOwn  = ($id === (int) (auth()->user()?->member_id ?? 0));
        $allowed = $this->aclCheck('view_all', 'Members_Controller', 'registration_export')
            || ($isOwn && $type === 'registration'
                && $this->aclCheck('view_own', 'Members_Controller', 'registration_export'));
        abort_unless($allowed, 403);

        [$pdfString, $filename] = $document->render($id, $type);

        return response($pdfString, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Odeslat stejné PDF (přihláška / ukončení / výpověď) e-mailem na první
     * e-mail kontakt hlavního uživatele. Jen staff (view_all).
     */
    public function registrationExportEmail(int $id, string $type, EmailRegistrationDocumentAction $send)
    {
        abort_unless($this->aclCheck('view_all', 'Members_Controller', 'registration_export'), 403);
        abort_unless(in_array($type, RegistrationDocument::TYPES, true), 404);

        $email = $send->handle($id, $type);
        if (!$email) {
            return back()->with('error', 'Člen nemá v kontaktech žádný e-mail.');
        }

        return back()->with('success', 'PDF „' . RegistrationDocument::SUBJECTS[$type] . '" zařazeno do fronty k odeslání na ' . $email . '.');
    }

    /**
     * Samoobslužná stránka — uživatel si sám spravuje, kterými kanály chce
     * dostávat oznámení. Vyžaduje jen auth, žádné ACL.
     */
    public function myNotificationsForm()
    {
        $memberId = (int) (auth()->user()->member_id ?? 0);
        $member   = $memberId ? app(MemberRepository::class)->find($memberId) : null;
        abort_if(!$member, 404, 'Účet není navázán na člena.');

        return view('account.notifications', ['member' => $member]);
    }

    public function myNotificationsUpdate(Request $request)
    {
        $memberId = (int) (auth()->user()->member_id ?? 0);
        $member   = $memberId ? app(MemberRepository::class)->find($memberId) : null;
        abort_if(!$member, 404, 'Účet není navázán na člena.');

        app(MemberRepository::class)->setNotificationChannels($member,
            $request->boolean('notification_by_redirection'),
            $request->boolean('notification_by_email'),
            $request->boolean('notification_by_sms'),
        );

        return redirect()->route('me.notifications')
            ->with('success', 'Nastavení oznámení bylo uloženo.');
    }
}
