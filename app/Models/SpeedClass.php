<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedClass extends Model
{
    public $timestamps = false;
    protected $table = 'speed_classes';
    protected $fillable = ['name', 'd_ceil', 'd_rate', 'u_ceil', 'u_rate', 'price',
        'regular_member_default', 'applicant_default'];
    protected $casts = [
        'regular_member_default' => 'boolean',
        'applicant_default'      => 'boolean',
        'price'                  => 'decimal:2',
    ];

    public function members()
    {
        return $this->hasMany(Member::class, 'speed_class_id');
    }

    // Ceníková cena tarifu jako štítek, "400 Kč" / "—" když není nastavena (NULL).
    public function priceLabel(): string
    {
        return $this->price === null ? '—' : rtrim(rtrim(number_format((float) $this->price, 2, ',', ' '), '0'), ',') . ' Kč';
    }

    // Hodnota do formuláře: "400" / "350,5" / "" když NULL (bez oddělovače tisíců, čárka desetinná).
    public function priceInput(): string
    {
        return $this->price === null ? '' : rtrim(rtrim(number_format((float) $this->price, 2, ',', ''), '0'), ',');
    }

    // Format bps to human readable
    public static function formatBps(?int $bps): string
    {
        if (!$bps) return '0';
        if ($bps >= 1_000_000_000) return rtrim(rtrim(number_format($bps / 1_000_000_000, 3, '.', ''), '0'), '.') . ' Gbps';
        if ($bps >= 1_000_000)     return rtrim(rtrim(number_format($bps / 1_000_000, 3, '.', ''), '0'), '.') . ' Mbps';
        if ($bps >= 1_000)         return rtrim(rtrim(number_format($bps / 1_000, 3, '.', ''), '0'), '.') . ' Kbps';
        return $bps . ' bps';
    }

    // Format d/u pair as "250 Mbps / 50 Mbps" or "250 Mbps" if equal
    public static function formatPair(?int $d, ?int $u): string
    {
        $ds = self::formatBps($d);
        $us = self::formatBps($u);
        return $ds === $us ? $ds : $ds . ' / ' . $us;
    }

    // Parse "250/50" or "250M/50M" or "250" into [d_bps, u_bps]
    public static function parseSlash(string $input): array
    {
        $parts = explode('/', trim($input));
        $d = self::parseBps(trim($parts[0]));
        $u = isset($parts[1]) && trim($parts[1]) !== '' ? self::parseBps(trim($parts[1])) : $d;
        return [$d, $u];
    }

    // Parse "250M" "50M" "1G" "512K" or plain number (treated as Mbps)
    public static function parseBps(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(G|M|K|)(?:bps)?$/i', $value, $m)) {
            $n = (float) $m[1];
            return match (strtoupper($m[2])) {
                'G'     => (int) ($n * 1_000_000_000),
                'M'     => (int) ($n * 1_000_000),
                'K'     => (int) ($n * 1_000),
                default => (int) ($n * 1_000_000),  // bare number → Mbps
            };
        }
        // fallback: treat as Mbps
        return (int) ((float) $value * 1_000_000);
    }

    // Reconstruct slash notation for form pre-fill: "250/50" (in Mbps, no suffix)
    public static function toBpsSlash(?int $d, ?int $u): string
    {
        $dn = $d ? $d / 1_000_000 : 0;
        $un = $u ? $u / 1_000_000 : 0;
        return $dn == $un ? (string) (int) $dn : (int) $dn . '/' . (int) $un;
    }
}
