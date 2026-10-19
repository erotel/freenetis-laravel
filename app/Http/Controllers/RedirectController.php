<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Actions\ActivateIpRedirectAction;
use App\Domains\Notifications\Actions\ActivateMemberRedirectAction;
use App\Domains\Notifications\Actions\CancelIpRedirectAction;
use App\Domains\Notifications\Actions\CancelMemberRedirectAction;
use App\Domains\Notifications\Queries\ManualRedirectsQuery;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Domains\Members\Repositories\MemberRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ruční správa přesměrování (admin). Automatická přesměrování a hromadné
 * aktivace jsou v app/Domains/Notifications; NEZAMĚŇOVAT s RedirectionController
 * (stránka, kterou vidí přesměrovaný zákazník).
 */
class RedirectController extends Controller
{
    const ACL_SECTION = 'Redirect_Controller';
    const ACL_KEY     = 'redirect';

    const EXCLUDED_TYPES = ManualRedirectsQuery::EXCLUDED_TYPES;

    const TYPE_LABELS = [
        0  => 'Uživatelská zpráva',
        4  => 'Přerušení členství',
        5  => 'Dlužník',
        6  => 'Upozornění na placení',
        7  => 'Nepovolené přípojné místo',
        16 => 'Expirovaný test',
        20 => 'Velký dlužník',
        25 => 'Dlužník (člen)',
        26 => 'Upozornění (člen)',
    ];

    public function __construct(private ManualRedirectsQuery $query) {}

    private function canView(): bool   { return $this->aclCheck('view_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canEdit(): bool   { return $this->aclCheck('edit_all',   self::ACL_SECTION, self::ACL_KEY); }
    private function canDelete(): bool { return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY); }

    /**
     * Jen zprávy z nabídky — systémové typy (přerušení, expirovaný test…)
     * spravují automatiky a ručně aktivovat nejdou ani podvrženým POSTem.
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'message_id' => ['required', 'integer', Rule::in(array_keys($this->query->activatableMessages()))],
            'comment'    => 'nullable|string|max:1000',
        ]);
    }

    public function index(Request $request)
    {
        abort_unless($this->canView(), 403);

        return view('redirects.index', [
            'redirections' => $this->query->list($request->input('ip'), $request->input('member'), $request->input('type'), 50),
            'typeLabels'   => self::TYPE_LABELS,
            'canEdit'      => $this->canEdit(),
            'canDelete'    => $this->canDelete(),
            'filterIp'     => $request->input('ip', ''),
            'filterMember' => $request->input('member', ''),
            'filterType'   => $request->input('type', ''),
        ]);
    }

    public function activateToMember(Request $request, int $memberId, MemberRepository $members, ActivateMemberRedirectAction $activate)
    {
        abort_unless($this->canEdit(), 403);

        $member = $members->row($memberId);
        abort_if(!$member, 404);

        $messages = $this->query->activatableMessages();
        if (empty($messages)) {
            return redirect()->route('members.show', $memberId)->with('warning', 'Žádná zpráva není dostupná pro přesměrování.');
        }

        if ($request->isMethod('POST')) {
            $data = $this->validated($request);
            if ($activate->handle($memberId, (int) $data['message_id'], auth()->id(), $data['comment'] ?? null) === 0) {
                return redirect()->route('members.show', $memberId)->with('warning', 'Člen nemá žádné IP adresy.');
            }
            return redirect()->route('members.show', $memberId)->with('success', 'Přesměrování bylo aktivováno pro všechny IP adresy člena.');
        }

        return view('redirects.activate', [
            'member'   => $member,
            'ip'       => null,
            'messages' => $messages,
            'target'   => 'member',
            'formUrl'  => route('redirects.activate-member', $memberId),
            'backUrl'  => route('members.show', $memberId),
        ]);
    }

    public function activateToIp(Request $request, int $ipId, RedirectionRepository $redirects, ActivateIpRedirectAction $activate)
    {
        abort_unless($this->canEdit(), 403);

        $ip = $redirects->ip($ipId);
        abort_if(!$ip, 404);

        if ($request->isMethod('POST')) {
            $data = $this->validated($request);
            $activate->handle($ipId, (int) $data['message_id'], auth()->id(), $data['comment'] ?? null);
            return redirect()->route('ip_addresses.show', $ipId)->with('success', 'Přesměrování bylo aktivováno.');
        }

        return view('redirects.activate', [
            'member'   => null,
            'ip'       => $ip,
            'messages' => $this->query->activatableMessages(),
            'target'   => 'ip',
            'formUrl'  => route('redirects.activate-ip', $ipId),
            'backUrl'  => route('ip_addresses.show', $ipId),
        ]);
    }

    public function delete(int $ipId, int $msgId, CancelIpRedirectAction $cancel)
    {
        abort_unless($this->canDelete(), 403);
        $cancel->handle($ipId, $msgId);
        return redirect()->back()->with('success', 'Přesměrování bylo zrušeno.');
    }

    public function deleteFromMember(int $memberId, int $msgId, CancelMemberRedirectAction $cancel)
    {
        abort_unless($this->canDelete(), 403);
        $cancel->handle($memberId, $msgId);
        return redirect()->route('members.show', $memberId)->with('success', 'Přesměrování bylo zrušeno pro všechny IP adresy člena.');
    }

    /** Přesměrování člena po zprávách (karta člena) — viz RedirectionRepository. */
    public static function getMemberRedirections(int $memberId)
    {
        return app(RedirectionRepository::class)->memberRedirections($memberId);
    }
}
