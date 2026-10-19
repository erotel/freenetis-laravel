<?php

namespace App\Domains\Notifications\Support;

/**
 * Cesta přílohy e-mailu ke stažení — jen z povolených kořenů (legacy
 * /usr/share/freenetis/data a storage/app), aby endpoint přílohy nešel
 * zneužít ke čtení libovolného souboru na serveru.
 */
final class AttachmentFile
{
    /** @return string|false|null reálná cesta; false = mimo povolené kořeny; null = soubor neexistuje */
    public static function resolve(string $path): string|false|null
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        $roots = array_filter([realpath('/usr/share/freenetis/data'), realpath(storage_path('app')), realpath(base_path('storage/app'))]);
        foreach ($roots as $root) {
            if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        return false;
    }
}
