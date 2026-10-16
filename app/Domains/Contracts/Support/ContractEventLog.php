<?php

namespace App\Domains\Contracts\Support;

use App\Models\ContractEvent;

/** Zápis do historie smlouvy (contract_events). */
final class ContractEventLog
{
    /** @param int $jsonFlags příznaky json_encode pro meta (některé události historicky s JSON_UNESCAPED_UNICODE) */
    public static function record(int $contractId, string $event, array $meta, int $jsonFlags = 0): void
    {
        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => $event,
            'meta_json'   => json_encode($meta, $jsonFlags),
        ]);
    }
}
