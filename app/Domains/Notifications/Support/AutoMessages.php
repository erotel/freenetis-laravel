<?php

namespace App\Domains\Notifications\Support;

use App\Helpers\MemberType;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

/** Systémové zprávy automatických přesměrování (podle messages.type). */
final class AutoMessages
{
    public static function idOf(int $type): ?int
    {
        $id = Message::where('type', $type)->value('id');
        return $id ? (int) $id : null;
    }

    /** id obou dlužnických zpráv (zákazník 5 + člen 25). @return int[] */
    public static function debtorIds(): array
    {
        return DB::table('messages')->whereIn('type', [Message::DEBTOR_MESSAGE, Message::DEBTOR_MESSAGE_CLEN])->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Dlužnická zpráva pro typ člena: zákazník (2/18) → 5, člen (90/3) → 25, jinak žádná. */
    public static function debtorTypeFor(int $memberType): ?int
    {
        return match ($memberType) {
            MemberType::CUSTOMER, MemberType::PENDING_CUSTOMER => Message::DEBTOR_MESSAGE,
            MemberType::REGULAR, 3                             => Message::DEBTOR_MESSAGE_CLEN,
            default                                            => null,
        };
    }
}
