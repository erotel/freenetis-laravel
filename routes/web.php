<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\StreetController;
use App\Http\Controllers\TownController;
use App\Http\Controllers\UserController;
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

    Route::get('users/{userId}/contacts',                 [ContactController::class, 'showByUser'])->name('contacts.show_by_user');
    Route::get('users/{userId}/contacts/create',          [ContactController::class, 'create'])->name('contacts.create');
    Route::post('users/{userId}/contacts',                [ContactController::class, 'store'])->name('contacts.store');
    Route::get('users/{userId}/contacts/{contactId}/edit',[ContactController::class, 'edit'])->name('contacts.edit');
    Route::put('users/{userId}/contacts/{contactId}',     [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('users/{userId}/contacts/{contactId}',  [ContactController::class, 'destroy'])->name('contacts.destroy');
});
