<?php

namespace App\Domains\Notifications\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Pravidla automatické aktivace zpráv (messages_automatical_activations), viz Activation\ActivationRule. */
class ActivationRuleRepository
{
    public function forMessage(int $messageId): Collection
    {
        return DB::table('messages_automatical_activations')->where('message_id', $messageId)->get();
    }

    public function find(int $messageId, int $id): ?object
    {
        return DB::table('messages_automatical_activations')->where('id', $id)->where('message_id', $messageId)->first();
    }

    public function create(int $messageId, array $values): void
    {
        DB::table('messages_automatical_activations')->insert(['message_id' => $messageId] + $values);
    }

    public function update(int $messageId, int $id, array $values): void
    {
        DB::table('messages_automatical_activations')->where('id', $id)->where('message_id', $messageId)->update($values);
    }

    public function delete(int $messageId, int $id): void
    {
        DB::table('messages_automatical_activations')->where('id', $id)->where('message_id', $messageId)->delete();
    }
}
