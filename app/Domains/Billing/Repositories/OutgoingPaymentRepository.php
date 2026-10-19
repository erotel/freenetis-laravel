<?php

namespace App\Domains\Billing\Repositories;

use App\Models\BankAccount;
use App\Models\BankTransfer;
use App\Models\OutgoingPayment;
use App\Models\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Odchozí platby (vrácení přeplatků apod.): stav koncept → schváleno → exportováno → zaplaceno. */
class OutgoingPaymentRepository
{
    public function paginate(?string $status, int $perPage): LengthAwarePaginator
    {
        return OutgoingPayment::with(['bankAccount', 'createdBy', 'approvedBy'])
            ->orderByDesc('created_at')
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->paginate($perPage)->withQueryString();
    }

    public function findOrFail(int $id): OutgoingPayment
    {
        return OutgoingPayment::findOrFail($id);
    }

    public function findForDetailOrFail(int $id): OutgoingPayment
    {
        return OutgoingPayment::with(['bankAccount', 'transfer', 'createdBy', 'approvedBy'])->findOrFail($id);
    }

    public function bankAccountOrFail(int $id): BankAccount
    {
        return BankAccount::findOrFail($id);
    }

    /**
     * Účty pro export = účty ze směrování plateb podle typu člena (2, 90),
     * s příznakem, zda mají FIO API token (pak export jde rovnou do FIO).
     */
    public function exportAccounts(): Collection
    {
        $ids = array_filter([Setting::get('bank_account_member_type_2'), Setting::get('bank_account_member_type_90')]);
        return BankAccount::whereIn('id', $ids)->get()->map(fn ($ba) => [
            'id'        => $ba->id,
            'name'      => $ba->name,
            'full_nr'   => $ba->full_account_number,
            'has_token' => !empty($this->fioToken((int) $ba->id)),
        ]);
    }

    public function fioToken(int $bankAccountId): ?string
    {
        return Setting::get('fio_api_token_bank_account_' . $bankAccountId) ?: null;
    }

    public function approvedFor(int $bankAccountId): Collection
    {
        return OutgoingPayment::where('bank_account_id', $bankAccountId)->where('status', OutgoingPayment::STATUS_APPROVED)->get();
    }

    public function update(OutgoingPayment $payment, array $values): void
    {
        $payment->update($values);
    }

    /** Ke spárovanému bankovnímu převodu připíše „Čeká se na platbu z banky". */
    public function noteAwaitingBankPayment(int $transferId): void
    {
        BankTransfer::where('transfer_id', $transferId)->update([
            'comment' => DB::raw("CONCAT(COALESCE(comment, ''), IF(comment IS NULL OR comment = '', '', ' | '), 'Čeká se na platbu z banky')"),
        ]);
    }

    public function forTransfer(int $transferId): ?OutgoingPayment
    {
        return OutgoingPayment::where('transfer_id', $transferId)->first();
    }

    public function create(array $attributes): OutgoingPayment
    {
        return OutgoingPayment::create($attributes);
    }
}
