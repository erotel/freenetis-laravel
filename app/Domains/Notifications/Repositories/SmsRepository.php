<?php

namespace App\Domains\Notifications\Repositories;

use App\Models\SmsMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** SMS zprávy (sms_messages): odeslané (fronta pro bránu) i přijaté. */
class SmsRepository
{
    /**
     * Nejnovější první. S číslem (jen číslice) hledá v odesílateli i příjemci
     * v celé historii, jinak jen posledních 200 zpráv.
     */
    public function search(string $phone, ?int $type, ?int $state, int $perPage): LengthAwarePaginator
    {
        $query = SmsMessage::with('user')->select('sms_messages.*');

        if ($phone !== '') {
            $query->where(function ($q) use ($phone) {
                $q->where('sms_messages.receiver', 'like', "%{$phone}%")
                  ->orWhere('sms_messages.sender', 'like', "%{$phone}%");
            });
        } else {
            $last200 = SmsMessage::select('id')->orderByDesc('id')->limit(200);
            $query->joinSub($last200, 'last200', fn ($j) => $j->on('sms_messages.id', '=', 'last200.id'));
        }

        $query->orderByDesc('sms_messages.id');

        if ($type !== null) {
            $query->where('sms_messages.type', $type);
        }
        if ($state !== null) {
            $query->where('sms_messages.state', $state);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function findWithUserOrFail(int $id): SmsMessage
    {
        return SmsMessage::with('user')->findOrFail($id);
    }

    /** Přijatá nepřečtená → přečtená. */
    public function markRead(SmsMessage $sms): void
    {
        if ((int) $sms->type === SmsMessage::TYPE_RECEIVED && (int) $sms->state === SmsMessage::STATE_RECEIVED_UNREAD) {
            $sms->state = SmsMessage::STATE_RECEIVED_READ;
            $sms->save();
        }
    }

    public function queue(array $attributes): SmsMessage
    {
        return SmsMessage::create($attributes + [
            'sms_message_id' => null,
            'stamp'          => now(),
            'type'           => SmsMessage::TYPE_SENT,
            'state'          => SmsMessage::STATE_SENT_UNSENT,
            'message'        => null,
        ]);
    }

    public function deleteUnsent(): int
    {
        return SmsMessage::where('type', SmsMessage::TYPE_SENT)
            ->where('state', SmsMessage::STATE_SENT_UNSENT)
            ->delete();
    }

    /** Odchozí SMS čekající na odeslání, nejstarší první. */
    public function pendingOutgoing(int $limit)
    {
        return SmsMessage::where('type', SmsMessage::TYPE_SENT)
            ->where('state', SmsMessage::STATE_SENT_UNSENT)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** Výsledek odeslání: stav + hláška brány (max 100 znaků). */
    public function markDelivered(int $id, bool $ok, string $message): void
    {
        SmsMessage::whereKey($id)->update([
            'state'   => $ok ? SmsMessage::STATE_SENT_OK : SmsMessage::STATE_SENT_FAILED,
            'message' => substr($message, 0, 100),
        ]);
    }
}
