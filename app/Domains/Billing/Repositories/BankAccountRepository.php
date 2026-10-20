<?php

namespace App\Domains\Billing\Repositories;

use App\Models\BankAccount;
use App\Models\BankAccountAutoDownload;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Bankovní účty (bank_accounts), FIO API token účtu (šifrovaně v nastavení)
 * a pravidla automatického stahování výpisů (bank_accounts_automatical_downloads).
 */
class BankAccountRepository
{
    /** Hledá v názvu, čísle, kódu banky, IBAN, jménu člena; číslo i v id / id člena. */
    public function search(string $q): Collection
    {
        $query = BankAccount::with('member')->orderBy('id');

        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like, $q) {
                $w->where('name', 'like', $like)
                  ->orWhere('account_nr', 'like', $like)
                  ->orWhere('bank_nr',    'like', $like)
                  ->orWhere('IBAN',       'like', $like)
                  ->orWhereHas('member', fn ($m) => $m->where('name', 'like', $like));
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q)
                      ->orWhere('member_id', (int) $q);
                }
            });
        }

        return $query->get();
    }

    /** Bankovní účty sdružení (člen 1) — ty se stahují z FIO. */
    public function organizationAccounts(): Collection
    {
        return BankAccount::where('member_id', 1)->get(['id', 'name', 'account_nr', 'bank_nr']);
    }

    public function findOrFail(int $id): BankAccount
    {
        return BankAccount::findOrFail($id);
    }

    public function findWithMemberOrFail(int $id): BankAccount
    {
        return BankAccount::with('member')->findOrFail($id);
    }

    public function setIbanSwift(BankAccount $account, ?string $iban, ?string $swift): void
    {
        $account->IBAN  = $iban;
        $account->SWIFT = $swift;
        $account->save();
    }

    public function fioToken(int $bankAccountId): string
    {
        return (string) Setting::get('fio_api_token_bank_account_' . $bankAccountId, '');
    }

    public function setFioToken(int $bankAccountId, string $token): void
    {
        Setting::set('fio_api_token_bank_account_' . $bankAccountId, $token);
    }

    public function hasDownloadRules(int $bankAccountId): bool
    {
        return BankAccountAutoDownload::where('bank_account_id', $bankAccountId)->exists();
    }

    public function downloadRules(int $bankAccountId): Collection
    {
        return BankAccountAutoDownload::where('bank_account_id', $bankAccountId)->orderBy('id')->get();
    }

    public function findDownloadRuleOrFail(int $id): BankAccountAutoDownload
    {
        return BankAccountAutoDownload::findOrFail($id);
    }

    /** @param int[] $attributes hodnoty atributů typu (den, hodina …) → „26/13" */
    public function createDownloadRule(int $bankAccountId, int $type, array $attributes, bool $email, bool $sms): BankAccountAutoDownload
    {
        return BankAccountAutoDownload::create([
            'bank_account_id' => $bankAccountId,
            'type'            => $type,
            'attribute'       => count($attributes) ? implode('/', $attributes) : null,
            'email_enabled'   => $email,
            'sms_enabled'     => $sms,
        ]);
    }

    public function deleteDownloadRule(BankAccountAutoDownload $rule): void
    {
        $rule->delete();
    }
}
