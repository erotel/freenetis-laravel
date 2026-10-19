<?php

namespace App\Domains\Network\Repositories;

use App\Models\SmtpException;
use Illuminate\Support\Collection;

/** SMTP výjimky (smtp_exceptions) — vnitřní IP s povoleným odchozím SMTP, exportují se na brány. */
class SmtpExceptionRepository
{
    /** Řazeno podle IP číselně. */
    public function all(): Collection
    {
        return SmtpException::orderByRaw('INET_ATON(intip) ASC')->get();
    }

    public function findOrFail(int $id): SmtpException
    {
        return SmtpException::findOrFail($id);
    }

    public function ipTaken(string $ip, ?int $exceptId = null): bool
    {
        return SmtpException::where('intip', $ip)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function create(string $ip, string $user): SmtpException
    {
        return SmtpException::create(['intip' => $ip, 'user' => $user, 'datum' => now()->toDateString()]);
    }

    /** Zadavatel a datum jsou auditní stopa původního zápisu — mění se jen IP. */
    public function changeIp(SmtpException $exception, string $ip): void
    {
        $exception->update(['intip' => $ip]);
    }

    public function delete(SmtpException $exception): void
    {
        $exception->delete();
    }
}
