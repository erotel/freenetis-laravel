<?php

namespace App\Domains\Network\Actions;

use App\Domains\Access\Events\DeviceRegisteredFromRequest;
use App\Domains\Network\Repositories\DeviceRepository;
use App\Domains\Network\Support\SubnetAssignmentSync;
use App\Domains\Network\Support\TemplateIfaceInput;
use App\Domains\Notifications\Actions\SendTemplateToMemberAction;
use App\Models\ConnectionRequest;
use App\Models\User;
use App\Services\AddressResolverService;
use Illuminate\Support\Facades\DB;

/**
 * Registrace zařízení ze šablony: zařízení + rozhraní dle šablony + klientské
 * IPv4 (s odvozenou IPv6). Dotčené DHCP podsítě k přegenerování, povolené
 * podsítě člena, okamžité přesměrování čekajícího zákazníka.
 *
 * Ze žádosti o připojení: nerozhodnutá žádost se schválí (+ zpráva 13) a
 * dispatchne se {@see DeviceRegisteredFromRequest} (PPPoE onboarding v Access).
 * Validaci a oprávnění řeší volající.
 */
class RegisterDeviceFromTemplateAction
{
    private const MESSAGE_REQUEST_APPROVED = 13;

    public function __construct(
        private DeviceRepository $devices,
        private SubnetAssignmentSync $subnets,
        private AddressResolverService $addresses,
        private SendTemplateToMemberAction $sendTemplate,
    ) {
    }

    /**
     * @param array{user_id:int, name:string, type:int, buy_date?:?string, comment?:?string,
     *              town_id?:?int, street_id?:?int, street_number?:?string} $device
     * @param TemplateIfaceInput[] $ifaces
     * @return int id zařízení
     */
    public function handle(array $device, array $ifaces, ?int $connectionRequestId, ?int $decidedBy): int
    {
        $addressPointId = $this->addresses->resolveAddressPoint(
            $device['town_id'] ?? null,
            $device['street_id'] ?? null,
            $device['street_number'] ?? null
        );
        $memberId = User::find($device['user_id'])?->member_id;

        $touched  = [];
        $deviceId = DB::transaction(function () use ($device, $ifaces, $addressPointId, $memberId, &$touched) {
            $created = $this->devices->create([
                'user_id'          => $device['user_id'],
                'name'             => $device['name'],
                'type'             => $device['type'],
                'buy_date'         => $device['buy_date'] ?? now()->toDateString(),
                'comment'          => $device['comment'] ?? null,
                'address_point_id' => $addressPointId,
            ]);

            foreach ($ifaces as $in) {
                $iface = $this->devices->createIface($created->id, $in->type, $in->name, $in->mac);
                if ($in->ip !== null) {
                    $this->devices->assignClientIp($iface->id, (int) $in->subnetId, $in->ip, $memberId);
                    $touched[(int) $in->subnetId] = true;
                }
            }

            return (int) $created->id;
        });

        $subnetIds = array_keys($touched);
        $this->subnets->expireDhcp($subnetIds);
        $this->subnets->ipsAdded($memberId, $subnetIds);

        if ($connectionRequestId) {
            $this->approveRequest($connectionRequestId, $deviceId, $decidedBy);
            DeviceRegisteredFromRequest::dispatch($deviceId, $connectionRequestId);
        }

        return $deviceId;
    }

    /** Nerozhodnutou žádost schválit a navázat zařízení; žadateli zpráva 13. */
    private function approveRequest(int $crId, int $deviceId, ?int $decidedBy): void
    {
        $cr = ConnectionRequest::with('member')
            ->where('id', $crId)
            ->where('state', ConnectionRequest::STATE_UNDECIDED)
            ->first();
        if (!$cr) {
            return;
        }
        $cr->update([
            'device_id'       => $deviceId,
            'state'           => ConnectionRequest::STATE_APPROVED,
            'decided_user_id' => $decidedBy,
            'decided_at'      => now(),
        ]);
        $this->sendTemplate->handle(self::MESSAGE_REQUEST_APPROVED, $cr->member_id, [
            'member_name' => $cr->member?->name ?? '',
            'comment'     => $cr->comment ?? '',
        ]);
    }
}
