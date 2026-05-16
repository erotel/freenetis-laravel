<?php

/**
 * Verze FreenetIS Laravel portu.
 *
 * v1.x byla legacy Kohana (viz freenetis-kohana/version.php).
 * v2.x je Laravel rewrite. Bumpujeme při release-worthy změnách:
 *   - MAJOR: nekompatibilní DB schema / API změna
 *   - MINOR: nová funkce zachovávající kompatibilitu
 *   - PATCH: bugfix / drobnost
 *
 * Změnu commitujte samostatně ("chore: bump version to X.Y.Z"),
 * ať changelog snadno generuje `git log v2.0.0..v2.1.0`.
 */
return [
    'string' => '2.1.5',
];
