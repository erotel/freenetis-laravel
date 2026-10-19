<?php

namespace App\Rules;

use App\Models\Subnet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IPv4 adresa musí ležet v rozsahu zvolené podsítě (síť + maska). Dřív se
 * kontroloval jen formát — šlo uložit IP z jiné sítě, kterou pak DHCP
 * export rozdával v cizí podsíti.
 */
class IpInSubnet implements ValidationRule
{
    public function __construct(private mixed $subnetId)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $subnet = $this->subnetId ? Subnet::find((int) $this->subnetId) : null;
        $ip     = ip2long((string) $value);
        if (!$subnet || $ip === false) {
            return;   // chybějící podsíť / formát hlásí jiná pravidla
        }
        $net  = ip2long((string) $subnet->network_address);
        $mask = ip2long((string) $subnet->netmask);
        if ($net === false || $mask === false) {
            return;
        }
        if (($ip & $mask) !== ($net & $mask)) {
            $prefix = 32 - (int) log((~$mask & 0xFFFFFFFF) + 1, 2);
            $fail("IP adresa {$value} neleží v podsíti {$subnet->network_address}/{$prefix}.");
        }
    }
}
