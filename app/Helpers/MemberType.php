<?php

namespace App\Helpers;

class MemberType
{
    const APPLICANT    = 1;   // Žadatel
    const CUSTOMER     = 2;   // Zákazník (hlavní platící typ)
    const HONORARY     = 3;   // Čestný člen
    const SYMPATHIZING = 4;   // Sympatizant
    const NON_MEMBER   = 5;   // Nečlen
    const FEE_FREE     = 6;   // Člen osvobozený od poplatků
    const FORMER       = 15;  // Bývalý člen
    const REGULAR      = 90;  // Řádný člen (lokální)

    public static function labels(): array
    {
        return [
            self::APPLICANT    => 'Žadatel',
            self::CUSTOMER     => 'Zákazník',
            self::HONORARY     => 'Čestný člen',
            self::SYMPATHIZING => 'Sympatizant',
            self::NON_MEMBER   => 'Nečlen',
            self::FEE_FREE     => 'Člen osvobozený od poplatků',
            self::FORMER       => 'Bývalý člen',
            self::REGULAR      => 'Řádný člen',
        ];
    }

    public static function label(int $type): string
    {
        return self::labels()[$type] ?? 'Neznámý (' . $type . ')';
    }

    /**
     * Aktivní typy — platí za službu nebo jsou členy
     * Používá se v billing, QoS, notifikacích
     */
    public static function isActive(int $type): bool
    {
        return in_array($type, [
            self::CUSTOMER,
            self::HONORARY,
            self::FEE_FREE,
            self::REGULAR,
        ]);
    }

    /**
     * Zákazník — hlavní platící typ (type=2)
     * V této instalaci je type 2 přejmenován z původního názvu
     * ale logika zůstává stejná
     */
    public static function isCustomer(int $type): bool
    {
        return $type === self::CUSTOMER;
    }

    /**
     * Typy které se zobrazují v member selectboxech pro nové záznamy
     */
    public static function selectOptions(): array
    {
        return self::labels();
    }
}
