<?php

namespace App\Domains\Network\Support;

/** Vyplněné rozhraní z formuláře „zařízení ze šablony" (jedna definice šablony). */
final class TemplateIfaceInput
{
    public function __construct(
        public readonly int $type,
        public readonly ?string $name,
        public readonly ?string $mac,        // null = bez MAC (šablona ji nemá / nevyplněno)
        public readonly ?string $ip,         // null = bez IPv4
        public readonly ?int $subnetId,
    ) {
    }
}
