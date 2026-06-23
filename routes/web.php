<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\BankTransferController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\OutgoingPaymentController;
use App\Http\Controllers\FeeController;
use App\Http\Controllers\MemberFeeController;
use App\Http\Controllers\VariableSymbolController;
use App\Http\Controllers\IfaceController;
use App\Http\Controllers\LoginLogController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceTemplateController;
use App\Http\Controllers\IpAddressController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\SubnetController;
use App\Http\Controllers\VlanController;
use App\Http\Controllers\StreetController;
use App\Http\Controllers\TownController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AroGroupController;
use App\Http\Controllers\SpeedClassController;
use App\Http\Controllers\AllowedSubnetController;
use App\Http\Controllers\SavedFilterController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\EnumTypeController;
use App\Http\Controllers\MessageAutoSettingController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ForgottenPasswordController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WebInterfaceController;
use App\Http\Controllers\PublicIpNat1to1Controller;
use App\Http\Controllers\PublicPortForwardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\MembershipInterruptController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\LogQueueController;
use App\Http\Controllers\BankAccountAutoDownloadController;
use App\Http\Controllers\MemberWhitelistController;
use App\Http\Controllers\RedirectController;
use App\Http\Controllers\EmailQueueController;
use App\Http\Controllers\PohodaRefundQueueController;
use App\Http\Controllers\ConnectionRequestController;
use App\Http\Controllers\SmsMessageController;
use App\Http\Controllers\GponController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\WebAuthnController;
use Illuminate\Support\Facades\Route;

// ── Web Interface API (machine-to-machine, IP-restricted, no session auth) ──
Route::prefix('web-interface')->name('web-interface.')->group(function () {
    Route::get('redirected-ranges',           [WebInterfaceController::class, 'redirectedRanges'])->name('redirected-ranges');
    Route::get('allowed-ip-addresses',        [WebInterfaceController::class, 'allowedIpAddresses'])->name('allowed-ip-addresses');
    Route::get('unallowed-ip-addresses/{type?}', [WebInterfaceController::class, 'unallowedIpAddresses'])->name('unallowed-ip-addresses');
    Route::get('self-cancelable-ip-addresses',[WebInterfaceController::class, 'selfCancelableIpAddresses'])->name('self-cancelable-ip-addresses');
    Route::get('allowed-ip6-addresses',       [WebInterfaceController::class, 'allowedIp6Addresses'])->name('allowed-ip6-addresses');
    Route::get('ipv6-radius',                 [WebInterfaceController::class, 'ipv6Radius'])->name('ipv6-radius');
    Route::get('qos-json',                    [WebInterfaceController::class, 'qosJson'])->name('qos-json');
    Route::get('public-port-forwards-json',   [WebInterfaceController::class, 'publicPortForwardsJson'])->name('public-port-forwards-json');
    Route::get('public-ip-nat-1to1-json',     [WebInterfaceController::class, 'publicIpNat1to1Json'])->name('public-ip-nat-1to1-json');
    Route::get('public-port-forwards-txt',    [WebInterfaceController::class, 'publicPortForwardsTxt'])->name('public-port-forwards-txt');
    Route::get('public-ip-nat-1to1-txt',      [WebInterfaceController::class, 'publicIpNat1to1Txt'])->name('public-ip-nat-1to1-txt');
    Route::get('smtp-exceptions-json',        [WebInterfaceController::class, 'smtpExceptionsJson'])->name('smtp-exceptions-json');
    Route::get('smtp-exceptions-txt',         [WebInterfaceController::class, 'smtpExceptionsTxt'])->name('smtp-exceptions-txt');
});

// Legacy Kohana URL aliasy (web_interface/foo_bar) — produkční zařízení (MikroTik
// firewally, qos skripty atd.) je mají natvrdo. Mapujeme na nové dashed URL.
// Zaregistrujeme s a bez i18n prefixu (cs/, en/, sk/), protože Kohana URL měla
// jazykový prefix a venkovní zařízení ho mají hardcoded ve své konfiguraci.
$webInterfaceLegacyRoutes = function () {
    Route::get('redirected_ranges',              [WebInterfaceController::class, 'redirectedRanges']);
    Route::get('allowed_ip_addresses',           [WebInterfaceController::class, 'allowedIpAddresses']);
    Route::get('unallowed_ip_addresses/{type?}', [WebInterfaceController::class, 'unallowedIpAddresses']);
    Route::get('self_cancelable_ip_addresses',   [WebInterfaceController::class, 'selfCancelableIpAddresses']);
    Route::get('allowed_ip6_addresses',          [WebInterfaceController::class, 'allowedIp6Addresses']);
    Route::get('ipv6_radius',                    [WebInterfaceController::class, 'ipv6Radius']);
    Route::get('qos_json',                       [WebInterfaceController::class, 'qosJson']);
    Route::get('public_port_forwards_json',      [WebInterfaceController::class, 'publicPortForwardsJson']);
    Route::get('public_ip_nat_1to1_json',        [WebInterfaceController::class, 'publicIpNat1to1Json']);
    Route::get('public_port_forwards_txt',       [WebInterfaceController::class, 'publicPortForwardsTxt']);
    Route::get('public_ip_nat_1to1_txt',         [WebInterfaceController::class, 'publicIpNat1to1Txt']);
    Route::get('smtp_exceptions_json',           [WebInterfaceController::class, 'smtpExceptionsJson']);
    Route::get('smtp_exceptions_txt',            [WebInterfaceController::class, 'smtpExceptionsTxt']);
};

Route::prefix('web_interface')->group($webInterfaceLegacyRoutes);
foreach (['cs', 'en', 'sk'] as $lang) {
    Route::prefix($lang . '/web_interface')->group($webInterfaceLegacyRoutes);
}

// Login / logout
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware(['guest', 'throttle:10,1']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// ── Field Mode (mobile-first UI pro techniky v terénu) ──────────────────────
// Žádný nový auth — používá existující 'web' guard. Veřejné jsou jen login routy;
// zbytek je za 'auth' (guest redirect míří na field.login, viz bootstrap/app.php).
Route::prefix('field')->name('field.')->group(function () {
    Route::get('/', [FieldController::class, 'home'])->name('home');

    // PWA assety (veřejné) — servírované přes Laravel, aby /field/* nekolidovalo
    // s fyzickou složkou (directory listing). Viz FieldController PWA sekce.
    Route::get('/manifest.json', [FieldController::class, 'manifest'])->name('manifest');
    Route::get('/sw.js', [FieldController::class, 'serviceWorker'])->name('sw');
    Route::get('/offline.html', [FieldController::class, 'offline'])->name('offline');
    Route::get('/icon-{size}.png', [FieldController::class, 'icon'])->whereNumber('size')->name('icon');

    Route::get('/login', [FieldController::class, 'showLogin'])->name('login');
    Route::post('/login', [FieldController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/logout', [FieldController::class, 'logout'])->name('logout');

    Route::middleware('auth')->group(function () {
        Route::get('/search', [FieldController::class, 'search'])->name('search');
        Route::get('/search/results', [FieldController::class, 'searchResults'])->name('search.results');
        Route::get('/member/{id}', [FieldController::class, 'member'])->name('member')->whereNumber('id');
        Route::post('/member/{id}/note', [FieldController::class, 'addNote'])->name('member.note')->whereNumber('id');
        Route::get('/device/{id}', [FieldController::class, 'device'])->name('device')->whereNumber('id');
    });
});

// ── WebAuthn / biometrické přihlášení ───────────────────────────────────────
// Veřejné (login flow) — bez auth, ale v 'web' skupině kvůli session + CSRF.
Route::post('/webauthn/check',         [WebAuthnController::class, 'check'])->name('webauthn.check')->middleware('throttle:30,1');
Route::post('/webauthn/login/options', [WebAuthnController::class, 'loginOptions'])->name('webauthn.login.options')->middleware('throttle:30,1');
Route::post('/webauthn/login',         [WebAuthnController::class, 'login'])->name('webauthn.login')->middleware('throttle:30,1');

// Správa vlastních zařízení (přihlášený uživatel, libovolná role).
Route::middleware('auth')->group(function () {
    Route::get('/account/passkeys',            [WebAuthnController::class, 'index'])->name('webauthn.index');
    Route::post('/webauthn/register/options',  [WebAuthnController::class, 'registerOptions'])->name('webauthn.register.options');
    Route::post('/webauthn/register',          [WebAuthnController::class, 'register'])->name('webauthn.register');
    Route::delete('/webauthn/credential/{id}', [WebAuthnController::class, 'destroy'])->name('webauthn.credential.destroy')->whereNumber('id');
});

// Public self-registration (guest only)
Route::middleware('guest')->group(function () {
    Route::get('register', [RegistrationController::class, 'create'])->name('registration.create');
    Route::post('register', [RegistrationController::class, 'store'])->name('registration.store')->middleware('throttle:5,10');
    Route::get('register/success', [RegistrationController::class, 'success'])->name('registration.success');

    // Forgotten password
    Route::get('forgotten-password', [ForgottenPasswordController::class, 'create'])->name('forgotten-password');
    Route::post('forgotten-password', [ForgottenPasswordController::class, 'store'])->name('forgotten-password.store')->middleware('throttle:5,1');
    Route::get('forgotten-password/reset', [ForgottenPasswordController::class, 'reset'])->name('forgotten-password.reset');
    Route::post('forgotten-password/reset', [ForgottenPasswordController::class, 'update'])->name('forgotten-password.update')->middleware('throttle:5,1');
});

// Public ARES lookup (for registration form - no auth required)
Route::get('ares/lookup-public/{ico}', function (string $ico) {
    $ico = preg_replace('/\D/', '', $ico);
    if (strlen($ico) !== 8) {
        return response()->json(['error' => 'IČO musí mít 8 číslic.'], 400);
    }
    try {
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->get("https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{$ico}");
        if ($response->status() === 404) {
            return response()->json(['error' => 'Subjekt nenalezen v ARES.'], 404);
        }
        if (!$response->successful()) {
            return response()->json(['error' => 'Chyba při komunikaci s ARES.'], 500);
        }
        $data         = $response->json();
        $sidlo        = $data['sidlo'] ?? [];
        $mestNazev    = $sidlo['nazevObce'] ?? '';
        $uliceNazev   = $sidlo['nazevUlice'] ?? '';
        $cisloDomovni = $sidlo['cisloDomovni'] ?? '';
        $psc          = preg_replace('/\D/', '', $sidlo['psc'] ?? '');
        // 1. Exact match name + zip
        $town = \Illuminate\Support\Facades\DB::table('towns')
            ->where('town', $mestNazev)
            ->where('zip_code', $psc)
            ->first(['id', 'town', 'zip_code']);
        // 2. Fallback: zip only
        if (!$town && $psc) {
            $town = \Illuminate\Support\Facades\DB::table('towns')
                ->where('zip_code', $psc)
                ->first(['id', 'town', 'zip_code']);
        }
        // 3. Fallback: name only
        if (!$town && $mestNazev) {
            $town = \Illuminate\Support\Facades\DB::table('towns')
                ->where('town', 'LIKE', '%' . $mestNazev . '%')
                ->first(['id', 'town', 'zip_code']);
        }
        $street = null;
        if ($town && $uliceNazev) {
            $street = \Illuminate\Support\Facades\DB::table('streets')
                ->where('town_id', $town->id)
                ->where('street', 'LIKE', '%' . $uliceNazev . '%')
                ->first(['id', 'street']);
        }
        return response()->json([
            'nazev'       => $data['obchodniJmeno'] ?? '',
            'dic'         => $data['dic'] ?? '',
            'ulice'       => trim($uliceNazev . ' ' . $cisloDomovni),
            'ulice_nazev' => $uliceNazev,
            'cislo'       => $cisloDomovni,
            'mesto'       => $mestNazev,
            'psc'         => $psc,
            'town_id'     => $town?->id,
            'town_name'   => $town?->town,
            'street_id'   => $street?->id,
            'street_name' => $street?->street,
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Nepodařilo se kontaktovat ARES.'], 500);
    }
})->name('ares.lookup-public')->middleware('throttle:10,1');

// Public streets by town (for registration form - no auth required)
Route::get('streets/by-town-public/{townId}', function (int $townId) {
    return response()->json(
        \Illuminate\Support\Facades\DB::table('streets')
            ->where('town_id', $townId)
            ->orderBy('street')
            ->get(['id', 'street'])
    );
})->name('streets.by-town-public');

// First-run web wizard (active jen mezi 02-configure-app.sh a dokončením setupu)
Route::get('/setup',  [\App\Http\Controllers\SetupController::class, 'show'])->name('setup.show');
Route::post('/setup', [\App\Http\Controllers\SetupController::class, 'install'])->name('setup.install');

// Public captive-portal endpoint (fn-redirector na IGW přesměruje suspendované členy sem)
Route::get('/redirection', [\App\Http\Controllers\RedirectionController::class, 'show'])
    ->name('redirection');

// Public contract-sign flow (no auth — gated by HMAC access token)
Route::prefix('sign')->name('sign.')->group(function () {
    Route::get ('contract',         [\App\Http\Controllers\Contracts\PublicSignController::class, 'showContract'])->name('show');
    Route::post('info',             [\App\Http\Controllers\Contracts\PublicSignController::class, 'info'])->name('info');
    Route::get ('preview',          [\App\Http\Controllers\Contracts\PublicSignController::class, 'previewContract'])->name('preview');
    Route::post('otp/send',         [\App\Http\Controllers\Contracts\PublicSignController::class, 'sendOtp'])->name('otp.send');
    Route::post('otp/verify',       [\App\Http\Controllers\Contracts\PublicSignController::class, 'verifyOtp'])->name('otp.verify');
    Route::post('finalize',         [\App\Http\Controllers\Contracts\PublicSignController::class, 'finalizeContract'])->name('finalize');
    Route::get ('download',         [\App\Http\Controllers\Contracts\PublicSignController::class, 'downloadContract'])->name('download');
    Route::get ('addon',            [\App\Http\Controllers\Contracts\PublicSignController::class, 'showAddon'])->name('addon.show');
    Route::get ('addon/preview',    [\App\Http\Controllers\Contracts\PublicSignController::class, 'previewAddon'])->name('addon.preview');
    Route::post('addon/otp/send',   [\App\Http\Controllers\Contracts\PublicSignController::class, 'sendAddonOtp'])->name('addon.otp.send');
    Route::post('addon/otp/verify', [\App\Http\Controllers\Contracts\PublicSignController::class, 'verifyAddonOtp'])->name('addon.otp.verify');
    Route::post('terminate',        [\App\Http\Controllers\Contracts\PublicSignController::class, 'finalizeTermination'])->name('terminate');
});

// Protected area
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        $memberId = auth()->user()?->member_id ?? 1;
        return redirect()->route('members.show', $memberId);
    })->name('dashboard');

    Route::get('/search/ajax', [SearchController::class, 'ajax'])->name('search.ajax');
    Route::get('/search', [SearchController::class, 'index'])->name('search');

    Route::get('towns', [TownController::class, 'index'])->name('towns.index')
        ->middleware('acl:view_all,Address_points_Controller,town');
    Route::resource('towns', TownController::class)->except(['index']);
    Route::get('streets', [StreetController::class, 'index'])->name('streets.index')
        ->middleware('acl:view_all,Address_points_Controller,street');
    Route::resource('streets', StreetController::class)->except(['index']);
    Route::get('members/applicants', [MemberController::class, 'applicants'])->name('members.applicants');
    Route::get('members/pending-termination', [MemberController::class, 'pendingTermination'])->name('members.pending-termination');
    Route::get('members/{id}/registration-export/{type}', [MemberController::class, 'registrationExport'])->name('members.registration-export');
    Route::post('members/{id}/registration-export-email/{type}', [MemberController::class, 'registrationExportEmail'])->name('members.registration-export-email');
    Route::get('members/{id}/end-membership', [MemberController::class, 'endMembershipForm'])->name('members.end-membership');
    Route::post('members/{id}/end-membership', [MemberController::class, 'endMembership'])->name('members.end-membership.store');
    Route::post('members/{id}/restore', [MemberController::class, 'restore'])->name('members.restore');
    Route::post('members/{id}/restore-interrupt', [MemberController::class, 'restoreInterrupt'])->name('members.restore-interrupt');
    Route::post('members/{id}/approve', [MemberController::class, 'approve'])->name('members.approve');
    Route::get('members', [MemberController::class, 'index'])->name('members.index')
        ->middleware('acl:view_all,Members_Controller,members');
    Route::resource('members', MemberController::class)->except(['index']);
    Route::get('ares/lookup/{ico}', function (string $ico) {
        $ico = preg_replace('/\D/', '', $ico);
        if (strlen($ico) !== 8) {
            return response()->json(['error' => 'IČO musí mít 8 číslic.'], 400);
        }
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->get("https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{$ico}");
            if ($response->status() === 404) {
                return response()->json(['error' => 'Subjekt nenalezen v ARES.'], 404);
            }
            if (!$response->successful()) {
                return response()->json(['error' => 'Chyba při komunikaci s ARES.'], 500);
            }
            $data         = $response->json();
            $sidlo        = $data['sidlo'] ?? [];
            $mestNazev    = $sidlo['nazevObce'] ?? '';
            $uliceNazev   = $sidlo['nazevUlice'] ?? '';
            $cisloDomovni = $sidlo['cisloDomovni'] ?? '';
            $psc          = preg_replace('/\D/', '', $sidlo['psc'] ?? '');

            // Hledat město v DB dle názvu nebo PSČ
            $town = \Illuminate\Support\Facades\DB::table('towns')
                ->where(function ($q) use ($mestNazev, $psc) {
                    $q->where('town', 'LIKE', '%' . $mestNazev . '%');
                    if ($psc) {
                        $q->orWhere('zip_code', $psc);
                    }
                })
                ->first(['id', 'town', 'zip_code']);

            // Hledat ulici v DB dle názvu v rámci nalezeného města
            $street = null;
            if ($town && $uliceNazev) {
                $street = \Illuminate\Support\Facades\DB::table('streets')
                    ->where('town_id', $town->id)
                    ->where('street', 'LIKE', '%' . $uliceNazev . '%')
                    ->first(['id', 'street']);
            }

            return response()->json([
                'nazev'       => $data['obchodniJmeno'] ?? '',
                'dic'         => $data['dic'] ?? '',
                'ulice'       => trim($uliceNazev . ' ' . $cisloDomovni),
                'ulice_nazev' => $uliceNazev,
                'cislo'       => $cisloDomovni,
                'mesto'       => $mestNazev,
                'psc'         => $psc,
                'town_id'     => $town?->id,
                'town_name'   => $town?->town,
                'street_id'   => $street?->id,
                'street_name' => $street?->street,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Nepodařilo se kontaktovat ARES.'], 500);
        }
    })->name('ares.lookup')->middleware(['auth', 'throttle:10,1']);

    Route::get('streets/by-town/{townId}', function (int $townId) {
        return response()->json(
            DB::table('streets')->where('town_id', $townId)->orderBy('street')->get(['id', 'street'])
        );
    })->name('streets.by-town');

    Route::get('users/member/{memberId}', [UserController::class, 'showByMember'])
        ->name('users.by_member');
    Route::get('users/{id}/password', [UserController::class, 'changePassword'])
        ->name('users.password');
    Route::put('users/{id}/password', [UserController::class, 'updatePassword'])
        ->name('users.password.update');
    Route::get('users', [UserController::class, 'index'])->name('users.index')
        ->middleware('acl:view_all,Users_Controller,users');
    Route::resource('users', UserController::class)->except(['index']);

    Route::get('devices/dhcp-servers', [DeviceController::class, 'dhcpServers'])->name('devices.dhcp-servers');
    Route::get('devices/add/{userId?}', [DeviceController::class, 'createWithTemplate'])->name('devices.add');
    Route::post('devices/add', [DeviceController::class, 'storeWithTemplate'])->name('devices.store_template');
    Route::get('devices/create-from-cr/{crId}', [DeviceController::class, 'createFromConnectionRequest'])->name('devices.create_from_cr');
    Route::resource('devices', DeviceController::class);
    Route::resource('device-templates', DeviceTemplateController::class)
        ->parameters(['device-templates' => 'id'])
        ->names([
            'index'   => 'device_templates.index',
            'create'  => 'device_templates.create',
            'store'   => 'device_templates.store',
            'show'    => 'device_templates.show',
            'edit'    => 'device_templates.edit',
            'update'  => 'device_templates.update',
            'destroy' => 'device_templates.destroy',
        ]);
    Route::get('device-templates-export', [DeviceTemplateController::class, 'exportJson'])->name('device_templates.export');
    Route::get('users/{userId}/devices', [DeviceController::class, 'showByUser'])
        ->name('devices.by_user');
    Route::post('devices/{id}/engineers', [DeviceController::class, 'addEngineer'])
        ->name('devices.engineers.add');
    Route::delete('devices/{deviceId}/engineers/{userId}', [DeviceController::class, 'removeEngineer'])
        ->name('devices.engineers.remove');

    Route::resource('accounts', AccountController::class)->except(['destroy']);

    Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank_accounts.index')
        ->middleware('acl:view_all,Accounts_Controller,bank_accounts');
    Route::resource('bank-accounts', BankAccountController::class)
        ->only(['show'])
        ->names('bank_accounts');
    Route::get('bank-accounts/{id}/edit', [BankAccountController::class, 'edit'])
        ->name('bank_accounts.edit');
    Route::put('bank-accounts/{id}', [BankAccountController::class, 'update'])
        ->name('bank_accounts.update');

    // Auto-download rules per bank account
    Route::get('bank-accounts/{bankAccountId}/auto-downloads',  [BankAccountAutoDownloadController::class, 'index'])->name('bank_accounts.auto_downloads');
    Route::post('bank-accounts/{bankAccountId}/auto-downloads', [BankAccountAutoDownloadController::class, 'store'])->name('bank_accounts.auto_downloads.store');
    Route::delete('bank-accounts/auto-downloads/{id}',         [BankAccountAutoDownloadController::class, 'destroy'])->name('bank_accounts.auto_downloads.destroy');

    Route::get('bank-transfers/unidentified', [BankTransferController::class, 'showUnidentified'])
        ->name('bank_transfers.unidentified');
    Route::get('bank-transfers/{id}/refund',  [BankTransferController::class, 'refundForm'])->name('bank-transfers.refund.form');
    Route::post('bank-transfers/{id}/refund', [BankTransferController::class, 'refundStore'])->name('bank-transfers.refund.store');
    Route::get('bank-transfers/account/{bankAccountId}', [BankTransferController::class, 'showByBankAccount'])
        ->name('bank_transfers.by_account');

    Route::get('bank-statements/account/{bankAccountId}', [BankStatementController::class, 'showByBankAccount'])
        ->name('bank_statements.by_account');
    Route::delete('bank-statements/{id}', [BankStatementController::class, 'destroy'])
        ->name('bank_statements.destroy');

    Route::get('import/bank-file/{bankAccountId}',  [ImportController::class, 'uploadBankFile'])->name('import.upload_bank_file');
    Route::post('import/bank-file/{bankAccountId}', [ImportController::class, 'importBankFile'])->name('import.bank_file');
    Route::post('import/fio-api/{bankAccountId}/last',   [ImportController::class, 'fetchFromFioLast'])->name('import.fio_last');
    Route::post('import/fio-api/{bankAccountId}/period', [ImportController::class, 'fetchFromFioPeriod'])->name('import.fio_period');

    Route::get('outgoing-payments', [OutgoingPaymentController::class, 'index'])->name('outgoing_payments.index')
        ->middleware('acl:view_all,Accounts_Controller,bank_transfers');
    Route::get('outgoing-payments/{id}',                         [OutgoingPaymentController::class, 'show'])->name('outgoing_payments.show');
    Route::post('outgoing-payments/{id}/approve',                [OutgoingPaymentController::class, 'approve'])->name('outgoing_payments.approve');
    Route::post('outgoing-payments/{id}/cancel',                 [OutgoingPaymentController::class, 'cancel'])->name('outgoing_payments.cancel');
    Route::post('outgoing-payments/export/{bankAccountId}',      [OutgoingPaymentController::class, 'export'])->name('outgoing_payments.export');

    Route::get('fees', [FeeController::class, 'index'])->name('fees.index')
        ->middleware('acl:view_all,Fees_Controller,fees');
    Route::resource('fees', FeeController::class)->except(['index']);

    Route::get('members/{memberId}/fees',        [MemberFeeController::class, 'showByMember'])->name('members_fees.by_member');
    Route::get('members/{memberId}/fees/create', [MemberFeeController::class, 'create'])->name('members_fees.create');
    Route::post('members/{memberId}/fees',       [MemberFeeController::class, 'store'])->name('members_fees.store');
    Route::get('members-fees/{id}/edit',         [MemberFeeController::class, 'edit'])->name('members_fees.edit');
    Route::put('members-fees/{id}',              [MemberFeeController::class, 'update'])->name('members_fees.update');
    Route::delete('members-fees/{id}',           [MemberFeeController::class, 'destroy'])->name('members_fees.destroy');

    // transfers — named route before resource to avoid wildcard clash
    Route::get('transfers/account/{accountId}', [TransferController::class, 'showByAccount'])
        ->name('transfers.by_account');
    Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index')
        ->middleware('acl:view_all,Accounts_Controller,transfers');
    Route::resource('transfers', TransferController::class)->only(['show']);
    Route::get('subnets', [SubnetController::class, 'index'])->name('subnets.index')
        ->middleware('acl:view_all,Subnets_Controller,subnet');
    Route::resource('subnets', SubnetController::class)->except(['index']);
    Route::get('vlans', [VlanController::class, 'index'])->name('vlans.index')
        ->middleware('acl:view_all,Vlans_Controller,vlan');
    Route::resource('vlans', VlanController::class)->except(['index']);

    // ip-addresses — device-scoped create must come before resource to avoid wildcard conflict
    Route::get('ip-addresses/device/{deviceId}', [IpAddressController::class, 'create'])
        ->name('ip_addresses.create_for_device');
    Route::get('ip-addresses', [IpAddressController::class, 'index'])->name('ip_addresses.index')
        ->middleware('acl:view_all,Ip_addresses_Controller,ip_address');
    Route::resource('ip-addresses', IpAddressController::class)
        ->names('ip_addresses')
        ->parameters(['ip-addresses' => 'id'])
        ->except(['index']);

    Route::get('ifaces', [IfaceController::class, 'index'])->name('ifaces.index');
    Route::get('ifaces/create/{deviceId?}', [IfaceController::class, 'create'])->name('ifaces.create');
    Route::post('ifaces', [IfaceController::class, 'store'])->name('ifaces.store');
    Route::get('ifaces/{id}/edit', [IfaceController::class, 'edit'])->name('ifaces.edit');
    Route::put('ifaces/{id}', [IfaceController::class, 'update'])->name('ifaces.update');
    Route::delete('ifaces/{id}', [IfaceController::class, 'destroy'])->name('ifaces.destroy');
    Route::get('ifaces/{id}', [IfaceController::class, 'show'])->name('ifaces.show');

    Route::get('variable-symbols/account/{accountId}',  [VariableSymbolController::class, 'showByAccount'])->name('variable_symbols.by_account');
    Route::post('variable-symbols/account/{accountId}', [VariableSymbolController::class, 'store'])->name('variable_symbols.store');
    Route::delete('variable-symbols/{id}',              [VariableSymbolController::class, 'destroy'])->name('variable_symbols.destroy');

    Route::get('settings', [SettingController::class, 'index'])->name('settings.index')
        ->middleware('acl:view_all,Settings_Controller,finance_settings');
    Route::put('settings/gpon',     [SettingController::class, 'updateGpon'])->name('settings.update-gpon');
    Route::put('settings/finance',  [SettingController::class, 'updateFinance'])->name('settings.update-finance');
    Route::put('settings/email',    [SettingController::class, 'updateEmail'])->name('settings.update-email');
    Route::put('settings/system',   [SettingController::class, 'updateSystem'])->name('settings.update-system');
    Route::put('settings/users',    [SettingController::class, 'updateUsers'])->name('settings.update-users');
    Route::put('settings/network',  [SettingController::class, 'updateNetwork'])->name('settings.update-network');
    Route::post('settings/regenerate-dhcp-token', [SettingController::class, 'regenerateDhcpToken'])->name('settings.regenerate-dhcp-token');
    Route::put('settings/sms',      [SettingController::class, 'updateSms'])->name('settings.update-sms');
    Route::put('settings/sledovanitv',  [SettingController::class, 'updateSledovaniTv'])->name('settings.update-sledovanitv');
    Route::post('settings/sledovanitv/sync', [SettingController::class, 'syncSledovaniTv'])->name('settings.sledovanitv-sync');
    Route::put('settings/smlouvy',  [SettingController::class, 'updateSmlouvy'])->name('settings.update-smlouvy');
    Route::put('settings/registration', [SettingController::class, 'updateRegistration'])->name('settings.update-registration');
    Route::put('settings',          [SettingController::class, 'update'])->name('settings.update');

    Route::get('acl/create',       [AroGroupController::class, 'aclCreate'])->name('acl.create');
    Route::post('acl',             [AroGroupController::class, 'aclStore'])->name('acl.store');
    Route::get('acl/{id}/edit',    [AroGroupController::class, 'aclEdit'])->name('acl.edit');
    Route::put('acl/{id}',         [AroGroupController::class, 'aclUpdate'])->name('acl.update');
    Route::delete('acl/{id}',      [AroGroupController::class, 'aclDestroy'])->name('acl.destroy');

    Route::get('aro-groups', [AroGroupController::class, 'index'])->name('aro-groups.index')
        ->middleware('acl:view_all,Aro_groups_Controller,aro_group');
    Route::get('aro-groups/create',                   [AroGroupController::class, 'create'])->name('aro-groups.create');
    Route::post('aro-groups',                         [AroGroupController::class, 'store'])->name('aro-groups.store');
    Route::get('aro-groups/{id}',                     [AroGroupController::class, 'show'])->name('aro-groups.show');
    Route::get('aro-groups/{id}/edit',                [AroGroupController::class, 'edit'])->name('aro-groups.edit');
    Route::put('aro-groups/{id}',                     [AroGroupController::class, 'update'])->name('aro-groups.update');
    Route::delete('aro-groups/{id}',                  [AroGroupController::class, 'destroy'])->name('aro-groups.destroy');
    Route::post('aro-groups/{id}/users',              [AroGroupController::class, 'addUser'])->name('aro-groups.add-user');
    Route::delete('aro-groups/{id}/users/{userId}',   [AroGroupController::class, 'removeUser'])->name('aro-groups.remove-user');
    Route::post('aro-groups/{id}/acls',               [AroGroupController::class, 'addAcl'])->name('aro-groups.add-acl');
    Route::delete('aro-groups/{id}/acls/{aclId}',     [AroGroupController::class, 'removeAcl'])->name('aro-groups.remove-acl');

    Route::post('speed-classes/{id}/set-default/{type}', [SpeedClassController::class, 'setDefault'])
        ->name('speed_classes.set_default');
    Route::get('speed-classes', [SpeedClassController::class, 'index'])->name('speed_classes.index')
        ->middleware('acl:view_all,Speed_classes_Controller,speed_classes');
    Route::resource('speed-classes', SpeedClassController::class)
        ->names('speed_classes')
        ->parameters(['speed-classes' => 'id'])
        ->except(['index']);

    Route::get('members/{memberId}/allowed-subnets',  [AllowedSubnetController::class, 'showByMember'])->name('allowed_subnets.by_member');
    Route::put('members/{memberId}/allowed-subnets/count', [AllowedSubnetController::class, 'updateCount'])->name('allowed_subnets.update_count');
    Route::post('members/{memberId}/allowed-subnets', [AllowedSubnetController::class, 'store'])->name('allowed_subnets.store');
    Route::post('allowed-subnets/{id}/toggle',        [AllowedSubnetController::class, 'toggle'])->name('allowed_subnets.toggle');
    Route::delete('allowed-subnets/{id}',             [AllowedSubnetController::class, 'destroy'])->name('allowed_subnets.destroy');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index')
        ->middleware('acl:view_all,Accounts_Controller,invoices');
    Route::get('invoices/{id}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
    Route::get('invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('members/{memberId}/invoices', [InvoiceController::class, 'showByMember'])->name('invoices.by_member');

    Route::get('login-logs', [LoginLogController::class, 'index'])->name('login_logs.index')
        ->middleware('acl:view_all,Login_logs_Controller,logs');
    Route::get('login-logs/user/{userId}', [LoginLogController::class, 'showByUser'])->name('login_logs.by_user');

    Route::get('messages', [MessageController::class, 'index'])->name('messages.index')
        ->middleware('acl:view_all,Messages_Controller,message');
    Route::get('messages/create', [MessageController::class, 'create'])->name('messages.create');
    Route::post('messages', [MessageController::class, 'store'])->name('messages.store');
    Route::get('messages/{id}', [MessageController::class, 'show'])->name('messages.show');
    Route::get('messages/{id}/edit', [MessageController::class, 'edit'])->name('messages.edit');
    Route::put('messages/{id}', [MessageController::class, 'update'])->name('messages.update');
    Route::delete('messages/{id}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::post('messages/{id}/activate', [MessageController::class, 'activate'])->name('messages.activate');
    Route::delete('messages/{id}/deactivate/{ipAddressId}', [MessageController::class, 'deactivate'])->name('messages.deactivate');

    Route::get('messages/{messageId}/auto-settings',          [MessageAutoSettingController::class, 'index'])->name('message-auto-settings.index');
    Route::get('messages/{messageId}/auto-settings/create',  [MessageAutoSettingController::class, 'create'])->name('message-auto-settings.create');
    Route::post('messages/{messageId}/auto-settings',        [MessageAutoSettingController::class, 'store'])->name('message-auto-settings.store');
    Route::get('messages/{messageId}/auto-settings/{id}/edit', [MessageAutoSettingController::class, 'edit'])->name('message-auto-settings.edit');
    Route::put('messages/{messageId}/auto-settings/{id}',    [MessageAutoSettingController::class, 'update'])->name('message-auto-settings.update');
    Route::delete('messages/{messageId}/auto-settings/{id}', [MessageAutoSettingController::class, 'destroy'])->name('message-auto-settings.destroy');

    Route::get('enum-types', [EnumTypeController::class, 'index'])->name('enum-types.index')
        ->middleware('acl:view_all,Enum_types_Controller,enum_types');
    Route::get('enum-types/create', [EnumTypeController::class, 'create'])->name('enum-types.create');
    Route::post('enum-types', [EnumTypeController::class, 'store'])->name('enum-types.store');
    Route::get('enum-types/{id}/edit', [EnumTypeController::class, 'edit'])->name('enum-types.edit');
    Route::put('enum-types/{id}', [EnumTypeController::class, 'update'])->name('enum-types.update');
    Route::delete('enum-types/{id}', [EnumTypeController::class, 'destroy'])->name('enum-types.destroy');

    Route::get('users/{userId}/contacts',                 [ContactController::class, 'showByUser'])->name('contacts.show_by_user');
    Route::get('users/{userId}/contacts/create',          [ContactController::class, 'create'])->name('contacts.create');
    Route::post('users/{userId}/contacts',                [ContactController::class, 'store'])->name('contacts.store');
    Route::get('users/{userId}/contacts/{contactId}/edit',[ContactController::class, 'edit'])->name('contacts.edit');
    Route::put('users/{userId}/contacts/{contactId}',     [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('users/{userId}/contacts/{contactId}',  [ContactController::class, 'destroy'])->name('contacts.destroy');

    // ── Public IP NAT 1:1 ─────────────────────────────────────────────────────
    Route::get('public-ip-nat', [PublicIpNat1to1Controller::class, 'index'])->name('public-ip-nat.index')
        ->middleware('acl:view_all,Network_Controller,public_ip_nat');
    Route::get('public-ip-nat/{id}/edit',   [PublicIpNat1to1Controller::class, 'edit'])->name('public-ip-nat.edit');
    Route::put('public-ip-nat/{id}',        [PublicIpNat1to1Controller::class, 'update'])->name('public-ip-nat.update');
    Route::get('public-ip-nat/{id}/clear',  [PublicIpNat1to1Controller::class, 'clear'])->name('public-ip-nat.clear');

    // ── Public Port Forwards ──────────────────────────────────────────────────
    Route::get('public-port-forwards', [PublicPortForwardController::class, 'index'])->name('public-port-forwards.index')
        ->middleware('acl:view_all,Network_Controller,public_ports');
    Route::get('public-port-forwards/create',    [PublicPortForwardController::class, 'create'])->name('public-port-forwards.create');
    Route::post('public-port-forwards',          [PublicPortForwardController::class, 'store'])->name('public-port-forwards.store');
    Route::get('public-port-forwards/{id}/edit', [PublicPortForwardController::class, 'edit'])->name('public-port-forwards.edit');
    Route::put('public-port-forwards/{id}',      [PublicPortForwardController::class, 'update'])->name('public-port-forwards.update');
    Route::get('public-port-forwards/{id}/delete',[PublicPortForwardController::class, 'destroy'])->name('public-port-forwards.destroy');

    // ── SMTP Exceptions ───────────────────────────────────────────────────────
    Route::get('smtp-exceptions', [\App\Http\Controllers\SmtpExceptionController::class, 'index'])->name('smtp-exceptions.index')
        ->middleware('acl:view_all,Network_Controller,smtp_exceptions');
    Route::get('smtp-exceptions/create',     [\App\Http\Controllers\SmtpExceptionController::class, 'create'])->name('smtp-exceptions.create');
    Route::post('smtp-exceptions',           [\App\Http\Controllers\SmtpExceptionController::class, 'store'])->name('smtp-exceptions.store');
    Route::get('smtp-exceptions/{id}/edit',  [\App\Http\Controllers\SmtpExceptionController::class, 'edit'])->name('smtp-exceptions.edit');
    Route::put('smtp-exceptions/{id}',       [\App\Http\Controllers\SmtpExceptionController::class, 'update'])->name('smtp-exceptions.update');
    Route::get('smtp-exceptions/{id}/delete',[\App\Http\Controllers\SmtpExceptionController::class, 'destroy'])->name('smtp-exceptions.destroy');

    // ── Membership interrupts ─────────────────────────────────────────────────
    Route::get('members/{memberId}/membership-interrupts/create', [MembershipInterruptController::class, 'create'])->name('membership-interrupts.create');
    Route::post('members/{memberId}/membership-interrupts',       [MembershipInterruptController::class, 'store'])->name('membership-interrupts.store');
    Route::get('membership-interrupts/{id}/edit',  [MembershipInterruptController::class, 'edit'])->name('membership-interrupts.edit');
    Route::put('membership-interrupts/{id}',       [MembershipInterruptController::class, 'update'])->name('membership-interrupts.update');
    Route::delete('membership-interrupts/{id}',    [MembershipInterruptController::class, 'destroy'])->name('membership-interrupts.destroy');

    // ── Notifications ─────────────────────────────────────────────────────────
    Route::get('notifications/member/{id}',        [NotificationController::class, 'member'])->name('notifications.member');
    Route::post('notifications/member/{id}/notify',[NotificationController::class, 'notify'])->name('notifications.member.notify');
    Route::get('notifications/members',               [NotificationController::class, 'membersSelect'])->name('notifications.members.select');
    Route::get('notifications/members/{message_id}', [NotificationController::class, 'members'])->name('notifications.members');
    Route::post('notifications/members/{message_id}',[NotificationController::class, 'membersNotify'])->name('notifications.members.notify');

    // Member whitelists
    Route::get('member-whitelists',                              [MemberWhitelistController::class, 'index'])->name('member_whitelists.index');
    Route::get('members/{memberId}/whitelists/create',           [MemberWhitelistController::class, 'create'])->name('member_whitelists.create');
    Route::post('members/{memberId}/whitelists',                 [MemberWhitelistController::class, 'store'])->name('member_whitelists.store');
    Route::get('member-whitelists/{id}/edit',                    [MemberWhitelistController::class, 'edit'])->name('member_whitelists.edit');
    Route::put('member-whitelists/{id}',                         [MemberWhitelistController::class, 'update'])->name('member_whitelists.update');
    Route::delete('member-whitelists/{id}',                      [MemberWhitelistController::class, 'destroy'])->name('member_whitelists.destroy');

    // ── POHODA – fronta vratek ────────────────────────────────────────────────
    Route::get('pohoda-refund-queue', [PohodaRefundQueueController::class, 'index'])->name('pohoda-refund-queue.index');

    // ── E-mailová fronta ─────────────────────────────────────────────────────
    Route::get('email-queues',              [EmailQueueController::class, 'unsent'])->name('email_queues.unsent');
    Route::delete('email-queues',           [EmailQueueController::class, 'destroyUnsent'])->name('email_queues.destroy-unsent');
    Route::get('email-queues/sent',         [EmailQueueController::class, 'sent'])->name('email_queues.sent');
    Route::delete('email-queues/sent',      [EmailQueueController::class, 'destroySent'])->name('email_queues.destroy-sent');
    Route::get('email-queues/{id}/show',    [EmailQueueController::class, 'show'])->name('email_queues.show');
    Route::get('email-queues/{id}/attachments/{attachmentId}', [EmailQueueController::class, 'downloadAttachment'])->name('email_queues.attachment');
    Route::post('email-queues/{id}/resend', [EmailQueueController::class, 'resend'])->name('email_queues.resend');
    Route::delete('email-queues/{id}',      [EmailQueueController::class, 'destroy'])->name('email_queues.destroy');

    // ── SMS zprávy ───────────────────────────────────────────────────────────
    Route::get('sms-messages',             [SmsMessageController::class, 'index'])->name('sms_messages.index');
    Route::get('sms-messages/create',      [SmsMessageController::class, 'create'])->name('sms_messages.create');
    Route::post('sms-messages',            [SmsMessageController::class, 'store'])->name('sms_messages.store');
    Route::delete('sms-messages/unsent',   [SmsMessageController::class, 'destroyUnsent'])->name('sms_messages.destroy-unsent');
    Route::get('sms-messages/{id}',        [SmsMessageController::class, 'show'])->name('sms_messages.show');

    // ── Žádosti o připojení ──────────────────────────────────────────────────
    Route::get('connection-requests',                                    [ConnectionRequestController::class, 'index'])->name('connection_requests.index');
    Route::get('connection-requests/{id}',                               [ConnectionRequestController::class, 'show'])->name('connection_requests.show');
    Route::get('members/{memberId}/connection-requests',                 [ConnectionRequestController::class, 'showByMember'])->name('connection_requests.by_member');
    Route::get('connection-requests/create/{subnetId}/{ipAddress}',      [ConnectionRequestController::class, 'create'])->name('connection_requests.create');
    Route::post('connection-requests',                                   [ConnectionRequestController::class, 'store'])->name('connection_requests.store');
    Route::match(['GET','POST'], 'connection-requests/{id}/approve',     [ConnectionRequestController::class, 'approve'])->name('connection_requests.approve');
    Route::delete('connection-requests/{id}/reject',                     [ConnectionRequestController::class, 'reject'])->name('connection_requests.reject');

    // ── Přesměrování (Redirect) ───────────────────────────────────────────────
    Route::get('redirections',                                       [RedirectController::class, 'index'])->name('redirects.index');
    Route::match(['GET','POST'], 'members/{memberId}/redirections/activate', [RedirectController::class, 'activateToMember'])->name('redirects.activate-member');
    Route::match(['GET','POST'], 'ip-addresses/{ipId}/redirections/activate', [RedirectController::class, 'activateToIp'])->name('redirects.activate-ip');
    Route::delete('redirections/{ipId}/{msgId}',                     [RedirectController::class, 'delete'])->name('redirects.delete');
    Route::delete('members/{memberId}/redirections/{msgId}',         [RedirectController::class, 'deleteFromMember'])->name('redirects.delete-member');

    // Log queues (errors and logs)
    Route::get('log-queues',                    [LogQueueController::class, 'index'])->name('log_queues.index');
    Route::get('log-queues/{id}',               [LogQueueController::class, 'show'])->name('log_queues.show');
    Route::post('log-queues/{id}/close',        [LogQueueController::class, 'close'])->name('log_queues.close');
    Route::post('log-queues/close-all',         [LogQueueController::class, 'closeAll'])->name('log_queues.close-all');

    // Comments (account financial state, log_queue, ...)
    Route::get('comments/add-thread/{type}/{fkId}', [CommentController::class, 'addThread'])->name('comments.add-thread');
    Route::get('comments/{threadId}/add',           [CommentController::class, 'add'])->name('comments.add');
    Route::post('comments/{threadId}',              [CommentController::class, 'store'])->name('comments.store');
    Route::get('comments/{id}/edit',                [CommentController::class, 'edit'])->name('comments.edit');
    Route::put('comments/{id}',                     [CommentController::class, 'update'])->name('comments.update');
    Route::delete('comments/{id}',                  [CommentController::class, 'destroy'])->name('comments.destroy');

    Route::post('user/dark-mode', [UserController::class, 'toggleDarkMode'])->name('user.dark-mode');
    Route::get('stats',          [StatsController::class, 'index'])->name('stats.index');
    Route::get('stats/cashflow', [StatsController::class, 'cashflow'])->name('stats.cashflow');
    Route::get('saved-filters',       [SavedFilterController::class, 'index'])->name('saved-filters.index');
    Route::post('saved-filters',      [SavedFilterController::class, 'store'])->name('saved-filters.store');
    Route::delete('saved-filters/{id}', [SavedFilterController::class, 'destroy'])->name('saved-filters.destroy');

    // ── GPON OLT konfigurace ─────────────────────────────────────────────────
    Route::post('settings/gpon-olts',         [SettingController::class, 'storeGponOlt'])->name('settings.gpon-olts.store');
    Route::put('settings/gpon-olts/{id}',     [SettingController::class, 'updateGponOlt'])->name('settings.gpon-olts.update');
    Route::delete('settings/gpon-olts/{id}',  [SettingController::class, 'destroyGponOlt'])->name('settings.gpon-olts.destroy');

    // ── GPON modul ────────────────────────────────────────────────────────────
    Route::middleware('gpon_enabled')->group(function () {
        Route::get('gpon',              [GponController::class, 'index'])->name('gpon.index');
        Route::get('gpon/map',          [GponController::class, 'map'])->name('gpon.map');
        Route::get('gpon/{id}',         [GponController::class, 'show'])->name('gpon.show');
        Route::post('gpon/scan',        [GponController::class, 'scan'])->name('gpon.scan');
        Route::post('gpon/{id}/register',      [GponController::class, 'register'])->name('gpon.register');
        Route::post('gpon/{id}/remove',        [GponController::class, 'remove'])->name('gpon.remove');
        Route::delete('gpon/{id}',             [GponController::class, 'destroy'])->name('gpon.destroy');
        Route::post('gpon/{id}/update-member', [GponController::class, 'updateMember'])->name('gpon.update-member');
    });

    // ── Smlouvy ───────────────────────────────────────────────────────────────
    Route::get('contracts',                              [ContractController::class, 'index'])->name('contracts.index');
    Route::get('members/{id}/contract',                  [ContractController::class, 'show'])->name('contracts.show');
    Route::post('members/{id}/contract',                 [ContractController::class, 'create'])->name('contracts.create');
    Route::post('members/{id}/contract/send-link',       [ContractController::class, 'sendLink'])->name('contracts.send-link');
    Route::get('contracts/{id}/download',                [ContractController::class, 'download'])->name('contracts.download');
    Route::post('members/{id}/contract/addon',           [ContractController::class, 'createAddon'])->name('contracts.addon.create');
    Route::post('members/{id}/contract/addon/send-link', [ContractController::class, 'sendAddonLink'])->name('contracts.addon.send-link');
    Route::get('contracts/{id}/download-addon',          [ContractController::class, 'downloadAddon'])->name('contracts.addon.download');
    Route::delete('contracts/{id}/addon',                [ContractController::class, 'deleteAddon'])->name('contracts.addon.delete');

    // Device DHCP export — inside auth group but device self-call bypasses auth middleware
    Route::get('devices/{id}/export/{format}', [DeviceController::class, 'export'])
        ->name('devices.export')
        ->withoutMiddleware(\Illuminate\Auth\Middleware\Authenticate::class);

    // Kohana legacy URL compatibility
    Route::get('index.php/en/devices/export/{id}/{format}/text', [DeviceController::class, 'export'])
        ->withoutMiddleware(\Illuminate\Auth\Middleware\Authenticate::class);
});
