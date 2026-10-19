<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Actions\FetchFioStatementAction;
use App\Domains\Billing\Actions\ImportBankStatementAction;
use App\Domains\Billing\Actions\ImportNewFioPaymentsAction;
use App\Domains\Billing\Repositories\BankAccountRepository;
use App\Services\FioCsvParser;
use Illuminate\Http\Request;

/** Import bankovních výpisů FIO (soubor CSV, FIO API). Logika v app/Domains/Billing. */
class ImportController extends Controller
{
    private const ACL_SECTION = 'Accounts_Controller';
    private const ACL_VALUE   = 'bank_transfers';

    private const NO_TOKEN = 'Není nastaven FIO API token pro tento účet. Nastavte ho v editaci bankovního účtu.';

    public function __construct(private BankAccountRepository $accounts)
    {
    }

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function uploadBankFile(int $bankAccountId)
    {
        abort_unless($this->can('new_all'), 403);

        $account = $this->accounts->findOrFail($bankAccountId);

        return view('import.bank_file', compact('account'));
    }

    public function importBankFile(Request $request, int $bankAccountId, FioCsvParser $parser, ImportBankStatementAction $import)
    {
        abort_unless($this->can('new_all'), 403);

        $account = $this->accounts->findOrFail($bankAccountId);

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

        try {
            [$imported, $skipped] = $import->handle($account, $header, $rows, 'FIO CSV importer', auth()->id() ?? 1);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('bank_accounts.show', $bankAccountId)
            ->with('success', "Import dokončen: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    public function fetchFromFioLast(int $bankAccountId, FetchFioStatementAction $fetch, ImportBankStatementAction $import)
    {
        abort_unless($this->can('new_all'), 403);

        $account = $this->accounts->findOrFail($bankAccountId);
        $token   = FetchFioStatementAction::token($bankAccountId);
        if (empty($token)) {
            return back()->withErrors(['token' => self::NO_TOKEN]);
        }

        try {
            $parsed = $fetch->newSince($account, $token);
            if (!empty($parsed['errors'])) {
                return back()->with('error', implode(' ', $parsed['errors']));
            }
            if (empty($parsed['rows'])) {
                return redirect()->route('bank_transfers.by_account', $bankAccountId)->with('success', 'Žádné nové transakce.');
            }
            [$imported, $skipped] = $import->handle($account, $parsed['header'], $parsed['rows'], 'FIO API importer', auth()->id() ?? 1);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_transfers.by_account', $bankAccountId)
            ->with('success', "FIO API: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    public function fetchFromFioPeriod(Request $request, int $bankAccountId, FetchFioStatementAction $fetch, ImportBankStatementAction $import)
    {
        abort_unless($this->can('new_all'), 403);

        $account = $this->accounts->findOrFail($bankAccountId);
        $token   = FetchFioStatementAction::token($bankAccountId);
        if (empty($token)) {
            return back()->withErrors(['token' => self::NO_TOKEN]);
        }

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to'   => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        try {
            $parsed = $fetch->period($token, $data['date_from'], $data['date_to']);
            if (!empty($parsed['errors'])) {
                return back()->with('error', implode(' ', $parsed['errors']));
            }
            if (empty($parsed['rows'])) {
                return redirect()->route('bank_transfers.by_account', $bankAccountId)->with('success', 'Žádné transakce za zvolené období.');
            }
            [$imported, $skipped] = $import->handle($account, $parsed['header'], $parsed['rows'], 'FIO API importer', auth()->id() ?? 1);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('bank_transfers.by_account', $bankAccountId)
            ->with('success', "FIO API: importováno {$imported} převodů, přeskočeno {$skipped} duplicit.");
    }

    /** Plánovač — viz ImportNewFioPaymentsAction (ponecháno kvůli volajícím). */
    public function runFioImportForScheduler(int $bankAccountId): array
    {
        return app(ImportNewFioPaymentsAction::class)->handle($bankAccountId);
    }
}
