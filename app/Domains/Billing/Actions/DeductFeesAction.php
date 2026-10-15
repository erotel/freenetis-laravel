<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Events\FeesDeducted;
use App\Domains\Billing\Repositories\LedgerRepository;
use App\Domains\Billing\Support\AccountAttribute;
use App\Domains\Billing\Support\TransferType;
use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Měsíční srážka poplatků k datu: členský (type 1), dodatečné služby (6),
 * splátka vstupního (2) a zařízení (5) — v tomto pořadí (členský má přednost
 * na kredit). Každá sekce ve vlastní DB transakci; chyba sekce se zaloguje
 * a ostatní sekce běží dál.
 *
 * Prepaid pravidlo: strhne se jen to, co kredit pokryje celé. Nedostatek u
 * členského/služeb → payment_blocked=1 (dohnání po platbě řeší
 * PaymentBackchargeService); u vstupního/zařízení jen přeskočit (dluh, ne služba).
 *
 * Kdy a zda běžet (nastavení, den srážky) rozhoduje volající (fees:deduct).
 */
class DeductFeesAction
{
    /** @var Closure(string $level, string $message): void */
    private Closure $report;

    /** @var string[] sekce, jejichž transakce selhala (rollback) v posledním běhu */
    private array $failed = [];

    public function __construct(private LedgerRepository $ledger)
    {
    }

    /**
     * @param ?int     $only    jen tento člen (ladění/test)
     * @param ?Closure $report  fn(string $level 'info'|'warn'|'error', string $message)
     * @return ?int             počet srážek; null = chybí účty organizace
     */
    public function handle(string $date, ?int $only = null, ?Closure $report = null): ?int
    {
        $this->report = $report ?? fn () => null;
        $this->failed = [];

        $orgOperating      = $this->ledger->organizationAccountId(AccountAttribute::OPERATING);
        $orgInfrastructure = $this->ledger->organizationAccountId(AccountAttribute::INFRASTRUCTURE);
        if (!$orgOperating || !$orgInfrastructure) {
            return null;
        }

        $deducted = 0;
        $deducted += $this->memberFees($date, $orgOperating, $only);
        // Dodatečné služby po členském poplatku — členský má přednost na kredit.
        $deducted += $this->additionalServiceFees($date, $orgOperating, $only);
        $deducted += $this->entranceFees($date, $orgInfrastructure, $only);
        $deducted += $this->deviceFees($date, $orgOperating, $only);

        ($this->report)('info', "Done. Total deductions: {$deducted}");

        if ($deducted > 0) {
            FeesDeducted::dispatch($date, $deducted);
        }

        return $deducted;
    }

    /** @return string[] sekce, které v posledním běhu selhaly (jejich srážky se nezapsaly) */
    public function failures(): array
    {
        return $this->failed;
    }

    private function memberFees(string $date, int $orgAccount, ?int $only): int
    {
        // Výchozí poplatek dle nastavení (Settings → Finance), fallback na fees tabulku
        $defaultFeeIdType2  = (int) Setting::get('default_fee_member_type_2', 0);
        $defaultFeeIdType90 = (int) Setting::get('default_fee_member_type_90', 0);

        $defaultFeeType2  = $defaultFeeIdType2 ? $this->ledger->feeAmount($defaultFeeIdType2) : null;
        $defaultFeeType90 = $defaultFeeIdType90 ? $this->ledger->feeAmount($defaultFeeIdType90) : null;

        if ($defaultFeeType2 === null && $defaultFeeType90 === null) {
            ($this->report)('warn', 'Výchozí poplatky nejsou nastaveny v Nastavení → Finance. Používám fallback z tabulky fees.');
        }

        $accounts = $this->ledger->dueMemberFees($date, $only);

        $count   = 0; // úspěšně stržené
        $flagged = 0; // přeskočené pro nedostatek kreditu (payment_blocked=1)
        $cleared = 0; // strhlo se i přesto, že flag byl 1 → pojistka, flag se resetne
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($accounts as $account) {
                // Pořadí: individuální (members_fees) → cena tarifu (speed_classes.price)
                // → základní dle typu. NULL propadá dál, 0 = osvobozeno (feeAmount 0 se
                // níže přeskočí). Mirror App\Services\RegularFeeResolver.
                if ($account->individual_fee !== null) {
                    $feeAmount = (float) $account->individual_fee;
                } elseif ($account->tarif_fee !== null) {
                    $feeAmount = (float) $account->tarif_fee;
                } else {
                    $feeAmount = match ((int) $account->member_type) {
                        2  => $defaultFeeType2 ?? 0.0,
                        90 => $defaultFeeType90 ?? 0.0,
                        default => 0.0,
                    };
                }
                if ($feeAmount <= 0) continue;

                // Prepaid pravidlo: stržení proběhne pouze pokud kredit pokryje
                // celou částku. Kdo nemá → payment_blocked=1, transfer se nevytvoří
                // a dohánění proběhne po platbě (PaymentBackchargeService).
                if ((float) $account->balance < $feeAmount) {
                    $this->ledger->blockForPayment((int) $account->member_id, $date);
                    $flagged++;
                    continue;
                }

                $this->ledger->postFee((int) $account->account_id, $orgAccount, TransferType::MEMBER_FEE, $feeAmount,
                    $date, $creationDatetime, 'Automatická srážka členského příspěvku');

                // Pojistka: pokud byl flag 1 (např. nezavolal se Backcharge přes
                // import platby), reset — kredit teď zjevně stačí, stržení proběhlo.
                if ($this->ledger->unblockIfBlocked((int) $account->member_id)) $cleared++;

                $count++;
            }

            // Recalculate operating account balance from all transfers
            if ($count > 0) {
                $this->ledger->recalculateBalance($orgAccount);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DeductFees member fee error', ['error' => $e->getMessage()]);
            $this->failed[] = 'member fees';
            ($this->report)('error', 'Member fee deduction failed: ' . $e->getMessage());
        }

        ($this->report)('info', "Member fees: {$count} deductions, {$flagged} skipped (low credit, payment_blocked=1), {$cleared} flag-reset.");
        return $count;
    }

    /**
     * Dodatečné služby (enum 'additional service' + placená přípojná místa) jako
     * SAMOSTATNÝ převod type 6 — v převodech je vidět, co je tarif a co služba
     * navíc. Flag se tu jen NASTAVUJE, nikdy neresetuje — reset vlastní členská
     * srážka, aby úspěšná služba neodblokovala nezaplacený členský poplatek.
     */
    private function additionalServiceFees(string $date, int $orgAccount, ?int $only): int
    {
        $accounts = $this->ledger->dueAdditionalServiceFees($date, $only);

        $count   = 0; // úspěšně stržené
        $flagged = 0; // přeskočené pro nedostatek kreditu
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($accounts as $account) {
                $svcFee = (float) $account->svc_fee;
                if ($svcFee <= 0) continue;

                // Prepaid: strhnout jen pokud kredit pokryje celou částku služeb.
                if ((float) $account->balance < $svcFee) {
                    $this->ledger->blockForPayment((int) $account->member_id, $date);
                    $flagged++;
                    continue;
                }

                $this->ledger->postFee((int) $account->account_id, $orgAccount, TransferType::ADDITIONAL_SERVICE_FEE, $svcFee,
                    $date, $creationDatetime, 'Automatická srážka – dodatečné služby');
                $count++;
            }

            if ($count > 0) {
                $this->ledger->recalculateBalance($orgAccount);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('DeductFees additional service error', ['error' => $e->getMessage()]);
            $this->failed[] = 'additional services';
            ($this->report)('error', 'Additional service deduction failed: ' . $e->getMessage());
        }

        ($this->report)('info', "Additional services: {$count} deductions, {$flagged} skipped (low credit).");
        return $count;
    }

    /**
     * Splátka vstupního poplatku. Je to dluh, ne služba → při nedostatku kreditu
     * NEnastavujeme payment_blocked; splátka se dohoní příští měsíc.
     */
    private function entranceFees(string $date, int $orgAccount, ?int $only): int
    {
        return $this->installments(
            $this->ledger->dueEntranceFees($date, $only), $date, $orgAccount,
            TransferType::ENTRANCE_FEE, 'Automatická srážka vstupního poplatku',
            'DeductFees entrance fee error', 'Entrance fee deduction failed: ', 'Entrance fees'
        );
    }

    /** Splátka zařízení — stejné pravidlo jako vstupní poplatek. */
    private function deviceFees(string $date, int $orgAccount, ?int $only): int
    {
        return $this->installments(
            $this->ledger->dueDeviceFees($date, $only), $date, $orgAccount,
            TransferType::DEVICE_FEE, 'Automatická srážka poplatku za zařízení',
            'DeductFees device fee error', 'Device fee deduction failed: ', 'Device fees'
        );
    }

    private function installments(array $accounts, string $date, int $orgAccount, int $type, string $text,
                                  string $logMessage, string $errorPrefix, string $label): int
    {
        $count   = 0;
        $skipped = 0; // přeskočené pro nedostatek kreditu (NEnastavuje payment_blocked)
        $creationDatetime = date('Y-m-d H:i:s');

        DB::beginTransaction();
        try {
            foreach ($accounts as $account) {
                $amount = (float) $account->amount;
                if ($amount <= 0) continue;

                if ((float) $account->balance < $amount) {
                    $skipped++;
                    continue;
                }

                $this->ledger->postFee((int) $account->account_id, $orgAccount, $type, $amount, $date, $creationDatetime, $text);
                $count++;
            }

            if ($count > 0) {
                $this->ledger->recalculateBalance($orgAccount);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error($logMessage, ['error' => $e->getMessage()]);
            $this->failed[] = strtolower($label);
            ($this->report)('error', $errorPrefix . $e->getMessage());
        }

        ($this->report)('info', "{$label}: {$count} deductions, {$skipped} skipped (low credit).");
        return $count;
    }
}
