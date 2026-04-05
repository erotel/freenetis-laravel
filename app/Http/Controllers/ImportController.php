<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankTransfer;
use App\Models\Member;
use App\Models\Setting;
use App\Models\VariableSymbol;
use App\Models\Transfer;
use App\Http\Controllers\SettingController;
use App\Services\AclService;
use App\Services\FioCsvParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_transfers';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function uploadBankFile(int $bankAccountId)
    {
        abort_unless($this->can('new_all'), 403);

        $account = BankAccount::findOrFail($bankAccountId);

        return view('import.bank_file', compact('account'));
    }

    public function importBankFile(Request $request, int $bankAccountId, FioCsvParser $parser)
    {
        abort_unless($this->can('new_all'), 403);

        $account = BankAccount::findOrFail($bankAccountId);

        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:4096'],
        ]);

        $content = file_get_contents($request->file('csv_file')->getRealPath());

        // Convert encoding if needed (FIO exports UTF-8, but be safe)
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1250');
        }

        try {
            $parsed = $parser->parse($content);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['csv_file' => 'Chyba při parsování CSV: ' . $e->getMessage()]);
        }

        $header = $parsed['header'];
        $rows   = $parsed['rows'];
        $parseErrors = $parsed['errors'];

        if (!empty($parseErrors)) {
            return back()->withErrors(['csv_file' => implode(' ', $parseErrors)]);
        }

        if (empty($rows)) {
            return back()->with('error', 'CSV neobsahuje žádné záznamy.');
        }

        DB::transaction(function () use ($account, $header, $rows, &$imported, &$skipped) {
            $imported = 0;
            $skipped  = 0;

            // Create bank_statement
            $stmt = new BankStatement();
            $stmt->bank_account_id  = $account->id;
            $stmt->user_id          = auth()->id();
            $stmt->type             = 'FIO CSV importer';
            $stmt->from             = $header['dateStart'] ?? null;
            $stmt->to               = $header['dateEnd']   ?? null;
            $stmt->opening_balance  = $header['openingBalance'] ?? 0;
            $stmt->closing_balance  = $header['closingBalance'] ?? 0;
            $stmt->save();

            foreach ($rows as $row) {
                $transactionCode = isset($row['id_pohybu']) && $row['id_pohybu'] !== ''
                    ? (int) $row['id_pohybu']
                    : null;

                // Duplicate check
                if ($transactionCode !== null) {
                    if (BankTransfer::where('transaction_code', $transactionCode)->exists()) {
                        $skipped++;
                        continue;
                    }
                }

                $amount    = $row['castka'] ?? 0.0;
                $isIncoming = $amount >= 0;

                $bt = new BankTransfer();
                $bt->bank_statement_id = $stmt->id;
                $bt->transaction_code  = $transactionCode;
                $bt->variable_symbol   = isset($row['vs']) && $row['vs'] !== '' ? (int) $row['vs'] : null;
                $bt->constant_symbol   = $row['ks'] ?? null;
                $bt->specific_symbol   = $row['ss'] ?? null;
                $bt->comment           = isset($row['identifikace']) && $row['identifikace'] !== ''
                    ? $row['identifikace']
                    : ($row['zprava'] ?? null);

                if ($isIncoming) {
                    // money coming in: our account is destination
                    $bt->destination_id = $account->id;
                    $bt->origin_id      = null;
                } else {
                    // money going out: our account is origin
                    $bt->origin_id      = $account->id;
                    $bt->destination_id = null;
                }

                // Try to match system transfer via variable symbol
                if ($bt->variable_symbol !== null) {
                    $vsModel = VariableSymbol::where('variable_symbol', $bt->variable_symbol)->first();
                    if ($vsModel) {
                        // pvfree_filter_member_by_bank_account equivalent:
                        // check that the payment arrived on the correct bank account for the member's type
                        $memberAllowed = $this->filterMemberByBankAccount($vsModel->account_id, $account, $bt);

                        if ($memberAllowed) {
                            // Find matching system transfer
                            $transfer = Transfer::where(function ($q) use ($vsModel, $isIncoming) {
                                if ($isIncoming) {
                                    $q->where('destination_id', $vsModel->account_id);
                                } else {
                                    $q->where('origin_id', $vsModel->account_id);
                                }
                            })->latest('datetime')->first();

                            if ($transfer) {
                                $bt->transfer_id = $transfer->id;
                            }
                        }
                    }
                }

                $bt->save();
                $imported++;
            }
        });

        return redirect()
            ->route('bank_accounts.show', $bankAccountId)
            ->with('success', "Import dokončen: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    /**
     * Equivalent of Kohana's pvfree_filter_member_by_bank_account.
     *
     * Checks whether the payment arrived on the expected bank account for the
     * member type that owns $accountId. If the account routing rules are
     * configured in the config table (bank_account_member_type_<type>) and the
     * payment arrived on the wrong bank account, the transfer stays unmatched
     * and a note is written to $bt->comment.
     *
     * Returns true if matching should proceed, false if it should be blocked.
     */
    private function filterMemberByBankAccount(int $accountId, BankAccount $importAccount, BankTransfer $bt): bool
    {
        // Resolve member type via account → member relation
        $account = \App\Models\Account::find($accountId);
        if (!$account || !$account->member_id) {
            return true; // can't determine type, allow
        }

        $member = Member::find($account->member_id);
        if (!$member) {
            return true;
        }

        $memberType = (int) $member->type;
        $key        = sprintf(SettingController::KEY_BA_MEMBER_TYPE, $memberType);
        $expectedBaId = (int) Setting::get($key, 0);

        if ($expectedBaId === 0) {
            return true; // no rule configured for this type
        }

        if ($importAccount->id !== $expectedBaId) {
            $bt->comment = sprintf(
                'Platba patří %s (member_id=%d, typ=%d), ale přišla na špatný účet (%s). Očekáván účet id=%d.',
                $member->name,
                $member->id,
                $memberType,
                $importAccount->full_account_number,
                $expectedBaId
            );
            return false;
        }

        return true;
    }
}
