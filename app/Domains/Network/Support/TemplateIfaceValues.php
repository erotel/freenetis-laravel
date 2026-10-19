<?php

namespace App\Domains\Network\Support;

use App\Models\Iface;

/**
 * Definice rozhraní šablony z formuláře (pole iface_{typ}_…): wifi má
 * min/max počet (max ≥ min), ostatní počet; IP mají ethernet/wifi/interní,
 * MAC ethernet/wifi; jména položek iface_{typ}_names[].
 */
final class TemplateIfaceValues
{
    public static function fromInput(array $input): array
    {
        $values = [];
        foreach (array_keys(Iface::typeLabels()) as $type) {
            $prefix = "iface_{$type}";
            $names  = (array) ($input["{$prefix}_names"] ?? []);

            if ($type === Iface::WIRELESS) {
                $min = (int) ($input["{$prefix}_min_count"] ?? 0);
                $max = max($min, (int) ($input["{$prefix}_max_count"] ?? 0));
                $values[$type] = ['type' => $type, 'min_count' => $min, 'max_count' => $max,
                    'has_ip' => true, 'has_mac' => true, 'has_link' => true, 'items' => self::items($names, $max)];
            } else {
                $count = (int) ($input["{$prefix}_count"] ?? 0);
                $values[$type] = ['type' => $type, 'count' => $count,
                    'has_ip'  => in_array($type, [Iface::ETHERNET, Iface::WIRELESS, Iface::INTERNAL]),
                    'has_mac' => in_array($type, [Iface::ETHERNET, Iface::WIRELESS]),
                    'has_link' => true, 'items' => self::items($names, $count)];
            }
        }
        return $values;
    }

    private static function items(array $names, int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['name' => $names[$i] ?? ''];
        }
        return $items;
    }
}
