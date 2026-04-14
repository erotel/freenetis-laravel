<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankAccountAutoDownload;
use Illuminate\Http\Request;

class BankAccountAutoDownloadController extends Controller
{
    const ACL_SECTION = 'Accounts_Controller';
    const ACL_KEY     = 'bank_account_auto_down_config';

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

        $account = BankAccount::findOrFail($bankAccountId);
        $rules   = BankAccountAutoDownload::where('bank_account_id', $bankAccountId)
            ->orderBy('id')
            ->get();

        return view('bank_accounts.auto_downloads', [
            'account'    => $account,
            'rules'      => $rules,
            'typeLabels' => BankAccountAutoDownload::typeLabels(),
            'typeAttrs'  => BankAccountAutoDownload::typeAttributes(),
            'canAdd'     => $this->canAdd(),
            'canDelete'  => $this->canDelete(),
        ]);
    }

    // ── Create rule ───────────────────────────────────────────────────────────

    public function store(Request $request, int $bankAccountId)
    {
        abort_unless($this->canAdd(), 403);

        $account = BankAccount::findOrFail($bankAccountId);

        $type = (int) $request->input('type');
        $defs = BankAccountAutoDownload::typeAttributes()[$type] ?? null;

        abort_if($defs === null, 422);

        // Validate each required attribute
        $attrValues = $request->input('attribute', []);
        $rules      = ['type' => 'required|integer|in:1,2,3,4,5,6'];
        foreach ($defs as $i => $def) {
            $rules["attribute.{$i}"] = "required|integer|min:{$def['min']}|max:{$def['max']}";
        }
        $request->validate($rules);

        // Build attribute string (e.g. "13" or "26/13")
        $attrParts = [];
        foreach ($defs as $i => $def) {
            $attrParts[] = (int) ($attrValues[$i] ?? 0);
        }
        $attribute = count($attrParts) ? implode('/', $attrParts) : null;

        BankAccountAutoDownload::create([
            'bank_account_id' => $bankAccountId,
            'type'            => $type,
            'attribute'       => $attribute,
            'email_enabled'   => $request->boolean('email_enabled'),
            'sms_enabled'     => $request->boolean('sms_enabled'),
        ]);

        return redirect()->route('bank_accounts.auto_downloads', $bankAccountId)
            ->with('success', 'Pravidlo bylo přidáno.');
    }

    // ── Delete rule ───────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->canDelete(), 403);

        $rule = BankAccountAutoDownload::findOrFail($id);
        $bankAccountId = $rule->bank_account_id;
        $rule->delete();

        return redirect()->route('bank_accounts.auto_downloads', $bankAccountId)
            ->with('success', 'Pravidlo bylo smazáno.');
    }
}
