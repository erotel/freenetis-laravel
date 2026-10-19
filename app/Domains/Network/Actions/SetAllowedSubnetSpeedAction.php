<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\AllowedSubnetRepository;
use App\Models\AllowedSubnet;

/**
 * Vlastní rychlost přípojného místa (promítne se do QoS exportu per IP).
 * null = zdědit rychlost člena — takové místo nejde účtovat, účtování se ruší.
 */
class SetAllowedSubnetSpeedAction
{
    public function __construct(private AllowedSubnetRepository $places)
    {
    }

    public function handle(AllowedSubnet $as, ?int $speedClassId): void
    {
        $values = ['speed_class_id' => $speedClassId];
        if ($speedClassId === null) {
            $values['charged'] = false;
        }
        $this->places->update($as, $values);
    }
}
