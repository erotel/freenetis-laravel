<?php

namespace App\Domains\Notifications\Repositories;

use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Šablony zpráv (přesměrování / e-mail / SMS); systémové (type > 0) a uživatelské (0). */
class MessageRepository
{
    public function systemMessages(): Collection
    {
        return Message::where('type', '>', 0)->orderBy('type')->get();
    }

    public function userMessages(): Collection
    {
        return Message::where('type', 0)->orderBy('name')->get();
    }

    public function findOrFail(int $id): Message
    {
        return Message::findOrFail($id);
    }

    /** IP s aktivním přesměrováním na zprávu (kdo a kdy aktivoval), nejnovější první. */
    public function activeIps(int $messageId): Collection
    {
        return DB::table('messages_ip_addresses as mip')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mip.ip_address_id')
            ->leftJoin('users as u', 'u.id', '=', 'mip.user_id')
            ->where('mip.message_id', $messageId)
            ->select('mip.ip_address_id', 'ip.ip_address', 'u.login as activated_by', 'mip.comment', 'mip.datetime')
            ->orderByDesc('mip.datetime')
            ->get();
    }

    public function create(array $values): Message
    {
        return Message::create($values);
    }

    public function update(Message $message, array $values): void
    {
        $message->update($values);
    }

    /** Smaže zprávu i její přesměrování. */
    public function delete(Message $message): void
    {
        DB::table('messages_ip_addresses')->where('message_id', $message->id)->delete();
        $message->delete();
    }
}
