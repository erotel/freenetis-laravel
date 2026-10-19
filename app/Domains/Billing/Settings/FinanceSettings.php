<?php

namespace App\Domains\Billing\Settings;

use App\Models\Setting;
use App\Models\SpeedClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nastavení → Finance: automatická srážka a její den, výchozí poplatky podle
 * typu, prepaid model (blokace + přesměrování, den ukončení), ceník tarifů.
 */
class FinanceSettings
{
    public const KEYS = [
        'deduct_fees_automatically_enabled',
        'deduct_day',
        'finance_enabled',
        'default_fee_member_type_2',   // zákazník — default fee_id
        'default_fee_member_type_90',  // člen — default fee_id
        // Prepaid: DeductFees flagne místo přečerpání, redirect přes
        // PaymentBlockedRedirectService, ukončení 14. den (per VOP).
        'payment_blocked_redirect_enabled',
        'pending_termination_day',
        'admin_notification_email',
    ];

    public function values(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = Setting::get($key, '');
        }
        return $out;
    }

    /** Poplatky typu „regular member fee" pro výběr výchozího. */
    public function regularFees(): Collection
    {
        return DB::table('fees as f')
            ->join('enum_types as et', 'et.id', '=', 'f.type_id')
            ->whereRaw("LOWER(et.value) = 'regular member fee'")
            ->orderBy('f.name')
            ->get(['f.id', 'f.name', 'f.fee', 'f.from', 'f.to']);
    }

    public function tariffs(): Collection
    {
        return SpeedClass::orderBy('name')->get();
    }

    public function save(bool $autoDeduct, int|string $deductDay, bool $financeEnabled, mixed $defaultFeeCustomer, mixed $defaultFeeMember,
                         bool $blockedRedirect, int|string|null $terminationDay, ?string $adminEmail): void
    {
        Setting::set('deduct_fees_automatically_enabled', $autoDeduct ? 1 : 0);
        Setting::set('deduct_day', $deductDay);
        Setting::set('finance_enabled', $financeEnabled ? 1 : 0);
        Setting::set('default_fee_member_type_2', $defaultFeeCustomer);
        Setting::set('default_fee_member_type_90', $defaultFeeMember);
        Setting::set('payment_blocked_redirect_enabled', $blockedRedirect ? 1 : 0);
        Setting::set('pending_termination_day', $terminationDay ?? 14);
        Setting::set('admin_notification_email', $adminEmail ?? '');
    }

    /**
     * Ceník tarifů (speed_classes.price): prázdné = NULL (propadne na základní
     * poplatek), 0 = zdarma, desetinná čárka povolena, nečíselné se ignoruje.
     * @param array<int|string, mixed> $prices speed_class_id => vstup
     */
    public function saveTariffPrices(array $prices): void
    {
        foreach ($prices as $scId => $raw) {
            $raw = trim((string) $raw);
            if ($raw !== '' && !is_numeric(str_replace(',', '.', $raw))) {
                continue;
            }
            SpeedClass::where('id', (int) $scId)->update(['price' => $raw === '' ? null : (float) str_replace(',', '.', $raw)]);
        }
    }
}
