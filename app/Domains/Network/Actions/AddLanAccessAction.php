<?php

namespace App\Domains\Network\Actions;

use App\Domains\Network\Repositories\LanAccessRepository;
use App\Services\LanIsolationService;
use InvalidArgumentException;

/**
 * Povolí členovi cíle ve vnitřní síti (oddělené čárkou / mezerou / řádkem) —
 * vše, nebo nic. Při změně označí jeho DHCP subnety ke stažení routery.
 */
class AddLanAccessAction
{
    public function __construct(private LanAccessRepository $access, private LanIsolationService $lan)
    {
    }

    /**
     * @return int počet nově přidaných cílů
     * @throws InvalidArgumentException se všemi hláškami neplatných cílů
     */
    public function handle(int $memberId, string $destinations, ?string $comment, ?int $userId): int
    {
        $tokens = preg_split('/[\s,;]+/', $destinations, -1, PREG_SPLIT_NO_EMPTY);
        $normalized = [];
        $errors = [];
        foreach ($tokens as $t) {
            try {
                $normalized[] = $this->lan->normalizeDestination($t);
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        $added = 0;
        foreach (array_unique($normalized) as $dest) {
            $added += $this->access->add($memberId, $dest, $comment, $userId) ? 1 : 0;
        }

        if ($added) {
            $this->lan->expireSubnetsForMember($memberId);
        }

        return $added;
    }
}
