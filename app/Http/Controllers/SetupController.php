<?php

namespace App\Http\Controllers;

use App\Services\SetupService;
use Illuminate\Http\Request;

/**
 * First-run web wizard. Single-page form: dump upload XOR org+admin form.
 *
 * Život:
 *   - 02-configure-app.sh napsal storage/app/setup.token a vypsal URL ?t=TOKEN
 *   - admin otevře URL → wizard
 *   - submit → import dump | nebo import bootstrap.sql.gz + freenetis:install
 *   - smaže token → wizard 404 navždy
 */
class SetupController extends Controller
{
    public function __construct(private SetupService $setup) {}

    public function show(Request $request)
    {
        if (!$this->setup->isSetupNeeded()) {
            abort(404);
        }
        $token = (string) $request->query('t', '');
        if (!$this->setup->verifyToken($token)) {
            abort(403, 'Neplatný setup token. Najdeš ho ve výstupu 02-configure-app.sh nebo v storage/app/setup.token na serveru.');
        }
        return view('setup.wizard', ['token' => $token]);
    }

    public function install(Request $request)
    {
        if (!$this->setup->isSetupNeeded()) {
            abort(404);
        }
        $token = (string) $request->input('t', '');
        if (!$this->setup->verifyToken($token)) {
            abort(403);
        }

        $useDump = $request->input('install_mode') === 'dump';

        if ($useDump) {
            $request->validate([
                'sql_dump' => 'required|file|max:2097152', // 2 GB v KB (2 097 152 KB ≈ 2 GiB)
            ], [
                'sql_dump.required' => 'Vyber soubor s SQL dumpem.',
                'sql_dump.max'      => 'Soubor je větší než 2 GB.',
            ]);
        } else {
            $request->validate([
                'org_name'        => 'required|string|max:255',
                'admin_login'     => 'required|string|max:50|regex:/^[a-zA-Z0-9._-]+$/',
                'admin_password'  => 'required|string|min:8',
                'admin_password_confirm' => 'required|same:admin_password',
                'admin_name'      => 'required|string|max:50',
                'admin_surname'   => 'required|string|max:50',
                'admin_email'     => 'required|email|max:255',
            ], [
                'admin_login.regex'      => 'Login: jen [a-zA-Z0-9._-]',
                'admin_password.min'     => 'Heslo musí mít alespoň 8 znaků.',
                'admin_password_confirm.same' => 'Hesla se neshodují.',
            ]);
        }

        // Prevent timeout / max execution kvůli importu velkých dumpů.
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        try {
            if ($useDump) {
                $file = $request->file('sql_dump');
                $absPath = $file->getRealPath();
                $isGz    = str_ends_with(strtolower($file->getClientOriginalName()), '.gz');
                $this->setup->importDump($absPath, $isGz);
            } else {
                $this->setup->importBootstrap();
            }

            $this->setup->runMigrations();
            $this->setup->runSeeders();
            $this->setup->ensureEmailQueuesIndex();

            if (!$useDump) {
                $this->setup->createAdmin(
                    (string) $request->input('org_name'),
                    (string) $request->input('admin_login'),
                    (string) $request->input('admin_password'),
                    (string) $request->input('admin_name'),
                    (string) $request->input('admin_surname'),
                    (string) $request->input('admin_email'),
                );
            }

            $this->setup->cacheArtifacts();
            $this->setup->complete();
        } catch (\Throwable $e) {
            \Log::error('Setup wizard failed: ' . $e->getMessage(), ['exception' => $e]);
            return back()->withInput()->withErrors([
                'install' => 'Instalace selhala: ' . $e->getMessage(),
            ]);
        }

        return redirect('/login')->with('success', 'Instalace dokončena. Přihlas se.');
    }
}
