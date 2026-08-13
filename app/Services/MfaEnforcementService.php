<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MFA fáze B — vynucení dvoufázového přihlášení pro role (NIS2/ZoKB).
 *
 * Skupiny (aro_groups) označené v `mfa_required_groups` vyžadují MFA. Uživatel
 * vyžaduje MFA, pokud patří (přímo nebo přes hierarchii nadřazených skupin —
 * stejně jako dědí ACL) do některé označené skupiny.
 *
 * Prázdná tabulka = vynucení nikde → celý mechanismus je no-op (bezpečné nasadit).
 */
class MfaEnforcementService
{
    /** @return int[] ID skupin s povinným MFA */
    public function requiredGroupIds(): array
    {
        return DB::table('mfa_required_groups')->pluck('group_id')->map(fn($g) => (int) $g)->all();
    }

    public function isGroupRequired(int $groupId): bool
    {
        return DB::table('mfa_required_groups')->where('group_id', $groupId)->exists();
    }

    public function setGroupRequired(int $groupId, bool $required): void
    {
        if ($required) {
            DB::table('mfa_required_groups')->updateOrInsert(
                ['group_id' => $groupId],
                ['created_at' => now()]
            );
        } else {
            DB::table('mfa_required_groups')->where('group_id', $groupId)->delete();
        }
    }

    /** Vyžaduje daný uživatel MFA (dle svých skupin + jejich nadřazených)? */
    public function isRequiredForUser(int $userId): bool
    {
        $required = $this->requiredGroupIds();
        if (empty($required)) {
            return false; // nikde vynuceno → no-op
        }

        $direct = DB::table('groups_aro_map')->where('aro_id', $userId)->pluck('group_id')->all();
        if (empty($direct)) {
            return false;
        }

        $all = $this->withAncestors(array_map('intval', $direct));

        return !empty(array_intersect($all, $required));
    }

    /** Skupiny + všechny jejich nadřazené (walk parent_id nahoru). */
    private function withAncestors(array $groupIds): array
    {
        $parents = DB::table('aro_groups')->pluck('parent_id', 'id')->all(); // id => parent_id

        $seen  = [];
        $stack = $groupIds;
        while ($stack) {
            $g = (int) array_pop($stack);
            if (isset($seen[$g])) {
                continue;
            }
            $seen[$g] = true;
            $p = (int) ($parents[$g] ?? 0);
            if ($p !== 0) {
                $stack[] = $p;
            }
        }

        return array_keys($seen);
    }
}
