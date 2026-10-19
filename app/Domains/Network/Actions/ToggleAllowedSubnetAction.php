<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\AllowedSubnetRepository;
use App\Models\AllowedSubnet;

/** Přepne místo on/off; zapnutí na limitu vypne nejstarší zapnuté. */
class ToggleAllowedSubnetAction
{
    public function __construct(private AllowedSubnetRepository $places)
    {
    }

    /** @return bool nový stav (true = zapnuto) */
    public function handle(AllowedSubnet $as): bool
    {
        if ($as->enabled) {
            $this->places->update($as, ['enabled' => false]);
            return false;
        }

        $memberId = (int) $as->member_id;
        $limit    = $this->places->limit($memberId);
        if ($limit > 0 && $this->places->enabledCount($memberId) >= $limit && ($oldest = $this->places->oldestEnabled($memberId))) {
            $this->places->update($oldest, ['enabled' => false]);
        }
        $this->places->update($as, ['enabled' => true]);
        return true;
    }
}
