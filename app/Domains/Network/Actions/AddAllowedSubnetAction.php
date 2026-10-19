<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\AllowedSubnetRepository;

/** Přidá členovi zapnuté přípojné místo; nad limitem vypne nejstarší zapnutá (ne nové). */
class AddAllowedSubnetAction
{
    public function __construct(private AllowedSubnetRepository $places)
    {
    }

    public function handle(int $memberId, int $subnetId): void
    {
        $as    = $this->places->create($memberId, $subnetId);
        $limit = $this->places->limit($memberId);
        if ($limit <= 0) {
            return;
        }
        $enabled = $this->places->enabledCount($memberId);
        while ($enabled > $limit) {
            $oldest = $this->places->oldestEnabled($memberId, (int) $as->id);
            if (!$oldest) {
                break;
            }
            $this->places->update($oldest, ['enabled' => false]);
            $enabled--;
        }
    }
}
