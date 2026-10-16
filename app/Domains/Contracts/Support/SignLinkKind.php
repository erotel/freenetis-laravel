<?php

namespace App\Domains\Contracts\Support;

/** Druh podpisového odkazu: kam vede, jakou událost zapíše a zda jde o dodatek. */
enum SignLinkKind: string
{
    case Contract        = 'contract';
    case LegacyAddon     = 'addon';
    case TariffAddon     = 'tariff_addon';
    case ServiceAddon    = 'service_addon';
    case ConnectionPoint = 'connection_point';

    public function route(): string
    {
        return match ($this) {
            self::Contract        => 'sign.show',
            self::LegacyAddon     => 'sign.addon.show',
            self::TariffAddon     => 'sign.tariff_addon.show',
            self::ServiceAddon    => 'sign.service_addon.show',
            self::ConnectionPoint => 'sign.connection_point.show',
        };
    }

    /** contract_events.event při vydání odkazu. */
    public function issuedEvent(): string
    {
        return match ($this) {
            self::Contract        => 'link_issued',
            self::LegacyAddon     => 'addon_link_issued',
            self::TariffAddon     => 'tariff_addon_link_issued',
            self::ServiceAddon    => 'service_addon_link_issued',
            self::ConnectionPoint => 'connection_point_addon_link_issued',
        };
    }

    /** E-mail se šablonou dodatku (ne smlouvy). */
    public function isAddon(): bool
    {
        return $this !== self::Contract;
    }

    /** Odkaz na konkrétní řádek contract_addons (token nese aid). */
    public function hasAddonRow(): bool
    {
        return in_array($this, [self::TariffAddon, self::ServiceAddon, self::ConnectionPoint], true);
    }
}
