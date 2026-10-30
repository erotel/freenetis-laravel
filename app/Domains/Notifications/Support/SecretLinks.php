<?php

namespace App\Domains\Notifications\Support;

/**
 * Odkazy s tajným tokenem v uloženém těle e-mailu (reset hesla). Fronta
 * e-mailů je čitelná pro adminy — kdo by si token přečetl, převezme účet.
 */
final class SecretLinks
{
    private const TOKEN = '~(forgotten-password/reset\?request=)[A-Za-z0-9]+~';
    private const LINK  = '~https?://[^\s"\'<>]*forgotten-password/reset\?request=[A-Za-z0-9]+~';

    /** Pro zobrazení ve frontě: token nahradí tečkami, zbytek odkazu zůstane. */
    public static function mask(string $body): string
    {
        return (string) preg_replace(self::TOKEN, '$1•••', $body);
    }

    /** Po odeslání: celý odkaz z uloženého těla pryč (token už není potřeba). */
    public static function strip(string $body): string
    {
        return (string) preg_replace(self::LINK, '[odkaz odstraněn]', $body);
    }
}
