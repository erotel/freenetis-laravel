<?php

namespace App\Domains\Network\Queries;

use App\Models\DeviceTemplate;
use App\Models\EnumType;
use App\Models\Subnet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Data formulářů zařízení (read model): výběr člena, typy, šablony, podsítě s volnými IP, obce. */
class DeviceFormQuery
{
    /** Kolik volných IP v podsíti nabídnout JS našeptávači. */
    private const FREE_IPS_LIMIT = 30;

    /**
     * Výběr člena: jen členové s hlavním uživatelem (devices.user_id), každý
     * jednou (při víc legacy hlavních uživatelích ten s nejnižším id v pořadí).
     * Řazeno podle příjmení → jméno → id; display_name „Příjmení Jméno",
     * address_label = adresa člena (předvyplnění umístění zařízení).
     */
    public function membersForPicker(): Collection
    {
        return DB::table('members as m')
            ->join('users as u', function ($j) {
                $j->on('u.member_id', '=', 'm.id')
                  ->where('u.type', '=', User::MAIN_USER);
            })
            ->leftJoin('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->leftJoin('streets as st', 'st.id', '=', 'ap.street_id')
            ->leftJoin('towns as tn', 'tn.id', '=', 'ap.town_id')
            ->select(
                'm.id', 'm.name', 'u.id as user_id', 'u.login', 'u.surname', 'u.name as user_name',
                'm.address_point_id',
                'ap.town_id as ap_town_id', 'ap.street_id as ap_street_id', 'ap.street_number as ap_street_number',
                'st.street as ap_street',
                'tn.town as ap_town', 'tn.zip_code as ap_zip'
            )
            ->orderBy('u.surname')
            ->orderBy('u.name')
            ->orderBy('u.id')
            ->get()
            ->unique('id')
            ->values()
            ->map(function ($row) {
                // Fyzické osoby „Příjmení Jméno"; organizace/legacy bez jména → members.name.
                $surname = trim((string) $row->surname);
                $name    = trim((string) $row->user_name);
                $row->display_name = ($surname !== '' && $name !== '') ? $surname . ' ' . $name : $row->name;

                $street = trim(($row->ap_street ?? '') . ' ' . ($row->ap_street_number ?? ''));
                $town   = trim(($row->ap_town ?? '') . ' ' . ($row->ap_zip ?? ''));
                $row->address_label = trim(trim($street) . (($street && $town) ? ', ' : '') . $town);

                return $row;
            });
    }

    /** Typy zařízení (enum skupina zařízení) řazené podle názvu — modely. */
    public function deviceTypes(): Collection
    {
        return EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('value')->get();
    }

    /** Typy zařízení id => název, řazené podle id (formulář bez šablony). */
    public function deviceTypeOptions(): Collection
    {
        return EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('id')->pluck('value', 'id');
    }

    /** @return Collection<int, DeviceTemplate> */
    public function templates(): Collection
    {
        return DeviceTemplate::with('enumType')->get();
    }

    /** Šablony pro JS filtr podle typu: id, „název (typ)", enum_type_id. */
    public static function templateOptions(Collection $templates): Collection
    {
        return $templates->map(fn ($t) => [
            'id'           => $t->id,
            'name'         => $t->name . ($t->enumType ? ' (' . $t->enumType->value . ')' : ''),
            'enum_type_id' => $t->enum_type_id,
        ]);
    }

    public function subnets(): Collection
    {
        return Subnet::orderBy('name')->get();
    }

    /** Podsítě s prvními volnými IP (bez síťové a broadcast adresy) pro JS našeptávač. */
    public function subnetsWithFreeIps(): array
    {
        return Subnet::with('ipAddresses')->get()->map(function ($subnet) {
            $usedIps   = $subnet->ipAddresses->pluck('ip_address')->toArray();
            $network   = ip2long($subnet->network_address);
            $mask      = ip2long($subnet->netmask);
            $broadcast = $network | (~$mask & 0xFFFFFFFF);
            $freeIps   = [];
            for ($ip = $network + 1; $ip < $broadcast && count($freeIps) < self::FREE_IPS_LIMIT; $ip++) {
                $ipStr = long2ip($ip);
                if (!in_array($ipStr, $usedIps)) {
                    $freeIps[] = $ipStr;
                }
            }
            return [
                'id'      => $subnet->id,
                'network' => $subnet->network_address,
                'mask'    => $subnet->netmask,
                'label'   => $subnet->label,
                'freeIps' => $freeIps,
            ];
        })->values()->toArray();
    }

    public function towns(): Collection
    {
        return DB::table('towns')->orderBy('town')->get(['id', 'town', 'zip_code']);
    }

    /**
     * Společná data formuláře „zařízení ze šablony".
     *
     * @return array<string, mixed>
     */
    public function templateForm(?int $typeId, ?int $templateId): array
    {
        $rawTemplates     = $this->templates();
        $selectedTemplate = $templateId ? $rawTemplates->find($templateId) : null;

        return [
            'members'          => $this->membersForPicker(),
            'deviceTypes'      => $this->deviceTypes(),
            'templates'        => self::templateOptions($rawTemplates),
            'subnets'          => $this->subnets(),
            'selectedTemplate' => $selectedTemplate,
            'ifaceDefinitions' => $selectedTemplate ? $selectedTemplate->getIfaceDefinitions() : [],
            'selectedTypeId'   => $typeId,
            'subnetData'       => $this->subnetsWithFreeIps(),
            'towns'            => $this->towns(),
        ];
    }
}
