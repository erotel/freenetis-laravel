<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Trvalý zámek účtu po N neúspěšných přihlášeních (NIS2/ZoKB).
 *
 * Práh a délka zámku jsou konfigurovatelné přes Nastavení
 * (login_lockout_threshold / login_lockout_minutes). Zámek je časově omezený
 * (auto-unlock po vypršení) — brání trvalému DoS zamčení cizích účtů; admin
 * může odemknout dřív ({@see unlock()}).
 */
class LoginLockService
{
    public function threshold(): int
    {
        return max(1, (int) Setting::get('login_lockout_threshold', 5));
    }

    public function lockMinutes(): int
    {
        return max(1, (int) Setting::get('login_lockout_minutes', 15));
    }

    /** Vrací čas do kdy je účet zamčen, nebo null (není zamčen). */
    public function lockedUntil(int $userId): ?Carbon
    {
        $row = DB::table('user_login_locks')->where('user_id', $userId)->first();
        if (!$row || !$row->locked_until) {
            return null;
        }
        $until = Carbon::parse($row->locked_until);
        return $until->isFuture() ? $until : null;
    }

    public function isLocked(int $userId): bool
    {
        return $this->lockedUntil($userId) !== null;
    }

    /**
     * Zaznamená neúspěšné přihlášení. Vrací true, pokud se účet TÍMTO
     * selháním právě zamkl (pro logování/audit na volajícím).
     */
    public function recordFailure(int $userId): bool
    {
        $row   = DB::table('user_login_locks')->where('user_id', $userId)->first();
        $count = (int) ($row->failed_count ?? 0) + 1;

        $lockedUntil = null;
        $justLocked  = false;

        if ($count >= $this->threshold()) {
            $lockedUntil = now()->addMinutes($this->lockMinutes());
            $justLocked  = true;
            $count       = 0; // po zamčení počítadlo vynulovat
        }

        DB::table('user_login_locks')->updateOrInsert(
            ['user_id' => $userId],
            [
                'failed_count'   => $count,
                'locked_until'   => $lockedUntil,
                'last_failed_at' => now(),
                'updated_at'     => now(),
            ]
        );

        return $justLocked;
    }

    /** Úspěšné přihlášení — vynulovat stav. */
    public function clear(int $userId): void
    {
        DB::table('user_login_locks')->where('user_id', $userId)->delete();
    }

    /** Ruční odemčení adminem. */
    public function unlock(int $userId): void
    {
        $this->clear($userId);
    }

    /** Stav pro zobrazení v UI: ['failed_count' => int, 'locked_until' => ?Carbon]. */
    public function status(int $userId): array
    {
        $row   = DB::table('user_login_locks')->where('user_id', $userId)->first();
        $until = $row && $row->locked_until ? Carbon::parse($row->locked_until) : null;

        return [
            'failed_count' => (int) ($row->failed_count ?? 0),
            'locked_until' => ($until && $until->isFuture()) ? $until : null,
        ];
    }
}
