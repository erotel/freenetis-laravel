<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberLanAccess;
use App\Services\LanIsolationService;
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

    public function __construct(private LanIsolationService $lan) {}

    public function store(Request $request, int $memberId)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);
        Member::findOrFail($memberId);

        $data = $request->validate([
            'destinations' => 'required|string|max:2000',
            'comment'      => 'nullable|string|max:255',
        ]);

        // Víc cílů najednou (oddělené čárkou/mezerou/řádkem) — vše, nebo nic.
        $tokens = preg_split('/[\s,;]+/', $data['destinations'], -1, PREG_SPLIT_NO_EMPTY);
        $normalized = [];
        $errors = [];
        foreach ($tokens as $t) {
            try {
                $normalized[] = $this->lan->normalizeDestination($t);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) {
            return back()->withInput()->withErrors(['destinations' => implode(' ', $errors)]);
        }

        $added = 0;
        foreach (array_unique($normalized) as $dest) {
            $row = MemberLanAccess::firstOrCreate(
                ['member_id' => $memberId, 'destination' => $dest],
                ['comment' => $data['comment'] ?? null, 'created_by' => auth()->id()]
            );
            $added += $row->wasRecentlyCreated ? 1 : 0;
        }

        if ($added) {
            $this->lan->expireSubnetsForMember($memberId);
        }

        return redirect()->route('members.show', $memberId)
            ->with('success', $added
                ? "Přístup do vnitřní sítě přidán ({$added}). Routery ho převezmou do 5 minut."
                : 'Zadané cíle už člen má povolené.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->aclCheck('edit_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $row = MemberLanAccess::findOrFail($id);
        $memberId = (int) $row->member_id;
        $row->delete();
        $this->lan->expireSubnetsForMember($memberId);

        return redirect()->route('members.show', $memberId)
            ->with('success', "Přístup do {$row->destination} odebrán. Routery změnu převezmou do 5 minut.");
    }
}
