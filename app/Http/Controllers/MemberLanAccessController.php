<?php

namespace App\Http\Controllers;

use App\Domains\Access\Isolation\LanIsolationService;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Network\Actions\AddLanAccessAction;
use App\Domains\Network\Repositories\LanAccessRepository;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Výjimky z izolace klientů — kam do vnitřní sítě smí člen ze zákaznického
 * segmentu. Správa z detailu člena; routery si změnu stáhnou v DHCP exportu.
 * Viz {@see LanIsolationService}.
 */
class MemberLanAccessController extends Controller
{
    private const ACL_SECTION = 'Members_Controller';
    private const ACL_KEY     = 'lan_access';

    public function __construct(private LanIsolationService $lan, private LanAccessRepository $access) {}

    public function store(Request $request, int $memberId, MemberRepository $members, AddLanAccessAction $add)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);
        $members->findOrFail($memberId);

        $data = $request->validate([
            'destinations' => 'required|string|max:2000',
            'comment'      => 'nullable|string|max:255',
        ]);

        try {
            $added = $add->handle($memberId, $data['destinations'], $data['comment'] ?? null, auth()->id());
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['destinations' => $e->getMessage()]);
        }

        return redirect()->route('members.show', $memberId)
            ->with('success', $added
                ? "Přístup do vnitřní sítě přidán ({$added}). Routery ho převezmou do 5 minut."
                : 'Zadané cíle už člen má povolené.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $row = $this->access->findOrFail($id);
        $memberId = (int) $row->member_id;
        $this->access->delete($row);
        $this->lan->expireSubnetsForMember($memberId);

        return redirect()->route('members.show', $memberId)
            ->with('success', "Přístup do {$row->destination} odebrán. Routery změnu převezmou do 5 minut.");
    }
}
