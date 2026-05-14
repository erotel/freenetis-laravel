<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-member limit počtu současně povolených podsítí. Mirror Kohana tabulky
 * `allowed_subnets_counts` — záznam vzniká až když admin nastaví explicitní
 * hodnotu, jinak se použije globální default `allowed_subnets_default_count`.
 */
class AllowedSubnetsCount extends Model
{
    public $timestamps = false;
    protected $table = 'allowed_subnets_counts';
    protected $fillable = ['member_id', 'count'];
    protected $casts = ['count' => 'integer'];
}
