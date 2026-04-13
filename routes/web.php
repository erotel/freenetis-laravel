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
use App\Http\Controllers\EnumTypeController;
use App\Http\Controllers\MessageAutoSettingController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ForgottenPasswordController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\WebInterfaceController;
use App\Http\Controllers\PublicIpNat1to1Controller;
use App\Http\Controllers\PublicPortForwardController;
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
});

// Login / logout
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Public self-registration (guest only)
Route::middleware('guest')->group(function () {
    Route::get('register', [RegistrationController::class, 'create'])->name('registration.create');
    Route::post('register', [RegistrationController::class, 'store'])->name('registration.store');
    Route::get('register/success', [RegistrationController::class, 'success'])->name('registration.success');

    // Forgotten password
    Route::get('forgotten-password', [ForgottenPasswordController::class, 'create'])->name('forgotten-password');
    Route::post('forgotten-password', [ForgottenPasswordController::class, 'store'])->name('forgotten-password.store');
    Route::get('forgotten-password/reset', [ForgottenPasswordController::class, 'reset'])->name('forgotten-password.reset');
    Route::post('forgotten-password/reset', [ForgottenPasswordController::class, 'update'])->name('forgotten-password.update');
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
        $town = \Illuminate\Support\Facades\DB::table('towns')
            ->where(function ($q) use ($mestNazev, $psc) {
                $q->where('town', 'LIKE', '%' . $mestNazev . '%');
                if ($psc) $q->orWhere('zip_code', $psc);
            })
            ->first(['id', 'town', 'zip_code']);
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
})->name('ares.lookup-public');

// Public streets by town (for registration form - no auth required)
Route::get('streets/by-town-public/{townId}', function (int $townId) {
    return response()->json(
        \Illuminate\Support\Facades\DB::table('streets')
            ->where('town_id', $townId)
            ->orderBy('street')
            ->get(['id', 'street'])
    );
})->name('streets.by-town-public');

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
    Route::get('members/{id}/end-membership', [MemberController::class, 'endMembershipForm'])->name('members.end-membership');
    Route::post('members/{id}/end-membership', [MemberController::class, 'endMembership'])->name('members.end-membership.store');
    Route::post('members/{id}/restore', [MemberController::class, 'restore'])->name('members.restore');
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
    })->name('ares.lookup')->middleware('auth');

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

    Route::get('devices/add/{userId?}', [DeviceController::class, 'createWithTemplate'])->name('devices.add');
    Route::post('devices/add', [DeviceController::class, 'storeWithTemplate'])->name('devices.store_template');
    Route::resource('devices', DeviceController::class);
    Route::get('users/{userId}/devices', [DeviceController::class, 'showByUser'])
        ->name('devices.by_user');

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
    Route::put('settings/finance',  [SettingController::class, 'updateFinance'])->name('settings.update-finance');
    Route::put('settings/email',    [SettingController::class, 'updateEmail'])->name('settings.update-email');
    Route::put('settings/system',   [SettingController::class, 'updateSystem'])->name('settings.update-system');
    Route::put('settings/users',    [SettingController::class, 'updateUsers'])->name('settings.update-users');
    Route::put('settings/network',  [SettingController::class, 'updateNetwork'])->name('settings.update-network');
    Route::put('settings',          [SettingController::class, 'update'])->name('settings.update');

    Route::get('acl/create',       [AroGroupController::class, 'aclCreate'])->name('acl.create');
    Route::post('acl',             [AroGroupController::class, 'aclStore'])->name('acl.store');
    Route::get('acl/{id}/edit',    [AroGroupController::class, 'aclEdit'])->name('acl.edit');
    Route::put('acl/{id}',         [AroGroupController::class, 'aclUpdate'])->name('acl.update');
    Route::delete('acl/{id}',      [AroGroupController::class, 'aclDestroy'])->name('acl.destroy');

    Route::get('aro-groups', [AroGroupController::class, 'index'])->name('aro-groups.index')
        ->middleware('acl:view_all,Aro_groups_Controller,aro_groups');
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
        ->middleware('acl:view_all,Messages_Controller,messages');
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
        ->middleware('acl:view_all,Settings_Controller,enum_types');
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
    Route::post('public-ip-nat/{id}/toggle',[PublicIpNat1to1Controller::class, 'toggle'])->name('public-ip-nat.toggle');
    Route::post('public-ip-nat/{id}/clear', [PublicIpNat1to1Controller::class, 'clear'])->name('public-ip-nat.clear');

    // ── Public Port Forwards ──────────────────────────────────────────────────
    Route::get('public-port-forwards', [PublicPortForwardController::class, 'index'])->name('public-port-forwards.index')
        ->middleware('acl:view_all,Network_Controller,public_ports');
    Route::get('public-port-forwards/create',      [PublicPortForwardController::class, 'create'])->name('public-port-forwards.create');
    Route::post('public-port-forwards',            [PublicPortForwardController::class, 'store'])->name('public-port-forwards.store');
    Route::get('public-port-forwards/{id}/edit',   [PublicPortForwardController::class, 'edit'])->name('public-port-forwards.edit');
    Route::put('public-port-forwards/{id}',        [PublicPortForwardController::class, 'update'])->name('public-port-forwards.update');
    Route::post('public-port-forwards/{id}/toggle',[PublicPortForwardController::class, 'toggle'])->name('public-port-forwards.toggle');
    Route::delete('public-port-forwards/{id}',     [PublicPortForwardController::class, 'destroy'])->name('public-port-forwards.destroy');
});
