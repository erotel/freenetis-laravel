<?php

namespace App\Domains\Members\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zapomenuté heslo: dohledání účtu (login / e-mail / VS), token resetu
 * (v DB jen SHA-256 + expirace) a nastavení nového hesla.
 */
class PasswordResetRepository
{
    private const EMAIL = 20;

    public function userByLogin(string $login): ?object
    {
        return DB::table('users')->where('login', $login)->first();
    }

    public function user(int $id): ?object
    {
        return DB::table('users')->where('id', $id)->first();
    }

    /**
     * Uživatelé s tímto e-mailem, kteří mají přístup (člen nezamčený nebo bez
     * člena). E-mail může být v contacts víckrát i sdílený (rodinný).
     * @return Collection<int>
     */
    public function activeUserIdsByEmail(string $email): Collection
    {
        $contactIds = DB::table('contacts')->where('type', self::EMAIL)->where('value', $email)->pluck('id');
        if ($contactIds->isEmpty()) {
            return collect();
        }
        $userIds = DB::table('users_contacts')->whereIn('contact_id', $contactIds)->pluck('user_id')->unique();

        return DB::table('users')
            ->leftJoin('members', 'members.id', '=', 'users.member_id')
            ->whereIn('users.id', $userIds)
            ->where(function ($q) {
                $q->whereNull('members.locked')->orWhere('members.locked', 0);
            })
            ->pluck('users.id');
    }

    /** Hlavní uživatel člena s daným variabilním symbolem. */
    public function mainUserByVariableSymbol(string $vs): ?object
    {
        $accountId = DB::table('variable_symbols')->where('variable_symbol', $vs)->value('account_id');
        if (!$accountId) {
            return null;
        }
        $memberId = DB::table('accounts')->where('id', $accountId)->value('member_id');
        return $memberId ? DB::table('users')->where('member_id', $memberId)->where('type', 1)->first() : null;
    }

    public function email(int $userId): ?string
    {
        return DB::table('contacts')
            ->join('users_contacts', 'contacts.id', '=', 'users_contacts.contact_id')
            ->where('users_contacts.user_id', $userId)
            ->where('contacts.type', self::EMAIL)
            ->value('contacts.value');
    }

    public function setToken(int $userId, string $tokenHash, \DateTimeInterface $expiresAt): void
    {
        DB::table('users')->where('id', $userId)->update(['password_request' => $tokenHash, 'password_request_expires_at' => $expiresAt]);
    }

    public function userByTokenHash(string $tokenHash): ?object
    {
        return DB::table('users')->where('password_request', $tokenHash)->first();
    }

    /** Nové heslo a zneplatnění tokenu jedním UPDATE (token nejde použít znovu). */
    public function setPassword(int $userId, string $passwordHash): void
    {
        DB::table('users')->where('id', $userId)->update([
            'password'                    => $passwordHash,
            'password_request'            => null,
            'password_request_expires_at' => null,
        ]);
    }

    public function queueEmail(string $from, string $to, string $subject, string $body): void
    {
        DB::table('email_queues')->insert(['from' => $from, 'to' => $to, 'subject' => $subject, 'body' => $body, 'state' => 0]);
    }

    /** DB sessiony uživatele (jen při SESSION_DRIVER=database). */
    public function deleteDatabaseSessions(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }
}
