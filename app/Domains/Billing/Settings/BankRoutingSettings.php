<?php

namespace App\Domains\Billing\Settings;

use App\Models\BankAccount;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Nastavení → Banka: na který účet spolku se párují platby podle typu člena
 * (+ příznak „účel platby" u účtu), výchozí účet importu, e-mail účetní (Pohoda).
 */
class BankRoutingSettings
{
    public const KEY_MEMBER_TYPE = 'bank_account_member_type_%d';
    public const KEY_DEFAULT     = 'bank_account_default_import';

    public const MEMBER_TYPES = [
        2  => 'Zákazník (typ 2)',
        90 => 'Člen (typ 90)',
    ];

    /** Jen účty spolku (member_id 1), podle jména. */
    public function associationAccounts(): Collection
    {
        return BankAccount::where('member_id', 1)->orderBy('name')->get(['id', 'name', 'account_nr', 'bank_nr']);
    }

    /** @return array<int, array{label:string, key:string, bank_account_id:int, payment_purpose:int}> */
    public function routing(): array
    {
        $routing = [];
        foreach (self::MEMBER_TYPES as $type => $label) {
            $key = sprintf(self::KEY_MEMBER_TYPE, $type);
            $id  = (int) Setting::get($key, 0);
            $ba  = $id ? BankAccount::find($id) : null;
            $routing[$type] = ['label' => $label, 'key' => $key, 'bank_account_id' => $id, 'payment_purpose' => $ba ? (int) $ba->payment_purpose : 0];
        }
        return $routing;
    }

    public function defaultAccountId(): int
    {
        return (int) Setting::get(self::KEY_DEFAULT, 0);
    }

    public function accountantEmail(): mixed
    {
        return Setting::get('pohoda_accountant_email', '');
    }

    /**
     * @param array<int, array{0:int, 1:bool}> $routing typ člena => [účet (0 = žádný), účel platby]
     */
    public function save(array $routing, int $defaultAccountId, mixed $accountantEmail): void
    {
        foreach ($routing as $type => [$accountId, $paymentPurpose]) {
            Setting::set(sprintf(self::KEY_MEMBER_TYPE, $type), $accountId);
            if ($accountId > 0) {
                BankAccount::where('id', $accountId)->update(['payment_purpose' => $paymentPurpose ? 1 : 0]);
            }
        }
        Setting::set(self::KEY_DEFAULT, $defaultAccountId);
        Setting::set('pohoda_accountant_email', $accountantEmail);
    }
}
