<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Repositories\BankAccountRepository;
use App\Domains\Billing\Bank\DownloadSchedule;
use Illuminate\Http\Request;

class BankAccountAutoDownloadController extends Controller
{
    const ACL_SECTION = 'Accounts_Controller';
    const ACL_KEY     = 'bank_account_auto_down_config';

    public function __construct(private BankAccountRepository $accounts)
    {
    }

    private function canView(): bool
    {
        return $this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY);
    }

    private function canAdd(): bool
    {
        return $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY);
    }

    private function canDelete(): bool
    {
        return $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY);
    }

    // ── List rules for a bank account ─────────────────────────────────────────

    public function index(int $bankAccountId)
    {
        abort_unless($this->canView(), 403);

        $account = $this->accounts->findOrFail($bankAccountId);
        $rules   = $this->accounts->downloadRules($bankAccountId);

        return view('bank_accounts.auto_downloads', [
            'account'    => $account,
            'rules'      => $rules,
            'typeLabels' => DownloadSchedule::typeLabels(),
            'typeAttrs'  => DownloadSchedule::typeAttributes(),
            'canAdd'     => $this->canAdd(),
            'canDelete'  => $this->canDelete(),
        ]);
    }

    // ── Create rule ───────────────────────────────────────────────────────────

    public function store(Request $request, int $bankAccountId)
    {
        abort_unless($this->canAdd(), 403);

        $this->accounts->findOrFail($bankAccountId);

        $type = (int) $request->input('type');
        $defs = DownloadSchedule::typeAttributes()[$type] ?? null;

        abort_if($defs === null, 422);

        // Validate each required attribute
        $attrValues = $request->input('attribute', []);
        $rules      = ['type' => 'required|integer|in:1,2,3,4,5,6'];
        foreach ($defs as $i => $def) {
            $rules["attribute.{$i}"] = "required|integer|min:{$def['min']}|max:{$def['max']}";
        }
        $request->validate($rules);

        $attrParts = [];
        foreach ($defs as $i => $def) {
            $attrParts[] = (int) ($attrValues[$i] ?? 0);
        }

        $this->accounts->createDownloadRule($bankAccountId, $type, $attrParts, $request->boolean('email_enabled'), $request->boolean('sms_enabled'));

        return redirect()->route('bank_accounts.auto_downloads', $bankAccountId)
            ->with('success', 'Pravidlo bylo přidáno.');
    }

    // ── Delete rule ───────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);

        $rule = $this->accounts->findDownloadRuleOrFail($id);
        $bankAccountId = $rule->bank_account_id;
        $this->accounts->deleteDownloadRule($rule);

        return redirect()->route('bank_accounts.auto_downloads', $bankAccountId)
            ->with('success', 'Pravidlo bylo smazáno.');
    }
}
