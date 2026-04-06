<?php

use App\Http\Controllers\AccountController;
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
use App\Http\Controllers\SpeedClassController;
use App\Http\Controllers\AllowedSubnetController;
use Illuminate\Support\Facades\Route;

// Login / logout
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout')->middleware('auth');

// Protected area
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/search', fn() => redirect('/'))->name('search');

    Route::resource('towns', TownController::class);
    Route::resource('streets', StreetController::class);
    Route::resource('members', MemberController::class);

    Route::get('users/member/{memberId}', [UserController::class, 'showByMember'])
        ->name('users.by_member');
    Route::get('users/{id}/password', [UserController::class, 'changePassword'])
        ->name('users.password');
    Route::put('users/{id}/password', [UserController::class, 'updatePassword'])
        ->name('users.password.update');
    Route::resource('users', UserController::class);

    Route::get('devices/add/{userId?}', [DeviceController::class, 'createWithTemplate'])->name('devices.add');
    Route::post('devices/add', [DeviceController::class, 'storeWithTemplate'])->name('devices.store_template');
    Route::resource('devices', DeviceController::class);
    Route::get('users/{userId}/devices', [DeviceController::class, 'showByUser'])
        ->name('devices.by_user');

    Route::resource('accounts', AccountController::class)->except(['destroy']);

    Route::resource('bank-accounts', BankAccountController::class)
        ->only(['index', 'show'])
        ->names('bank_accounts');
    Route::get('bank-accounts/{id}/edit', [BankAccountController::class, 'edit'])
        ->name('bank_accounts.edit');
    Route::put('bank-accounts/{id}', [BankAccountController::class, 'update'])
        ->name('bank_accounts.update');

    Route::get('bank-transfers/unidentified', [BankTransferController::class, 'showUnidentified'])
        ->name('bank_transfers.unidentified');
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

    Route::get('outgoing-payments',                              [OutgoingPaymentController::class, 'index'])->name('outgoing_payments.index');
    Route::get('outgoing-payments/{id}',                         [OutgoingPaymentController::class, 'show'])->name('outgoing_payments.show');
    Route::post('outgoing-payments/{id}/approve',                [OutgoingPaymentController::class, 'approve'])->name('outgoing_payments.approve');
    Route::post('outgoing-payments/{id}/cancel',                 [OutgoingPaymentController::class, 'cancel'])->name('outgoing_payments.cancel');
    Route::post('outgoing-payments/export/{bankAccountId}',      [OutgoingPaymentController::class, 'export'])->name('outgoing_payments.export');

    Route::resource('fees', FeeController::class);

    Route::get('members/{memberId}/fees',        [MemberFeeController::class, 'showByMember'])->name('members_fees.by_member');
    Route::get('members/{memberId}/fees/create', [MemberFeeController::class, 'create'])->name('members_fees.create');
    Route::post('members/{memberId}/fees',       [MemberFeeController::class, 'store'])->name('members_fees.store');
    Route::get('members-fees/{id}/edit',         [MemberFeeController::class, 'edit'])->name('members_fees.edit');
    Route::put('members-fees/{id}',              [MemberFeeController::class, 'update'])->name('members_fees.update');
    Route::delete('members-fees/{id}',           [MemberFeeController::class, 'destroy'])->name('members_fees.destroy');

    // transfers — named route before resource to avoid wildcard clash
    Route::get('transfers/account/{accountId}', [TransferController::class, 'showByAccount'])
        ->name('transfers.by_account');
    Route::resource('transfers', TransferController::class)->only(['index', 'show']);
    Route::resource('subnets', SubnetController::class);
    Route::resource('vlans', VlanController::class);

    // ip-addresses — device-scoped create must come before resource to avoid wildcard conflict
    Route::get('ip-addresses/device/{deviceId}', [IpAddressController::class, 'create'])
        ->name('ip_addresses.create_for_device');
    Route::resource('ip-addresses', IpAddressController::class)
        ->names('ip_addresses')
        ->parameters(['ip-addresses' => 'id']);

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

    Route::get('settings',    [SettingController::class, 'index'])->name('settings.index');
    Route::put('settings',    [SettingController::class, 'update'])->name('settings.update');

    Route::post('speed-classes/{id}/set-default/{type}', [SpeedClassController::class, 'setDefault'])
        ->name('speed_classes.set_default');
    Route::resource('speed-classes', SpeedClassController::class)
        ->names('speed_classes')
        ->parameters(['speed-classes' => 'id']);

    Route::get('members/{memberId}/allowed-subnets',  [AllowedSubnetController::class, 'showByMember'])->name('allowed_subnets.by_member');
    Route::put('members/{memberId}/allowed-subnets/count', [AllowedSubnetController::class, 'updateCount'])->name('allowed_subnets.update_count');
    Route::post('members/{memberId}/allowed-subnets', [AllowedSubnetController::class, 'store'])->name('allowed_subnets.store');
    Route::post('allowed-subnets/{id}/toggle',        [AllowedSubnetController::class, 'toggle'])->name('allowed_subnets.toggle');
    Route::delete('allowed-subnets/{id}',             [AllowedSubnetController::class, 'destroy'])->name('allowed_subnets.destroy');

    Route::get('login-logs', [LoginLogController::class, 'index'])->name('login_logs.index');
    Route::get('login-logs/user/{userId}', [LoginLogController::class, 'showByUser'])->name('login_logs.by_user');

    Route::get('users/{userId}/contacts',                 [ContactController::class, 'showByUser'])->name('contacts.show_by_user');
    Route::get('users/{userId}/contacts/create',          [ContactController::class, 'create'])->name('contacts.create');
    Route::post('users/{userId}/contacts',                [ContactController::class, 'store'])->name('contacts.store');
    Route::get('users/{userId}/contacts/{contactId}/edit',[ContactController::class, 'edit'])->name('contacts.edit');
    Route::put('users/{userId}/contacts/{contactId}',     [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('users/{userId}/contacts/{contactId}',  [ContactController::class, 'destroy'])->name('contacts.destroy');
});
