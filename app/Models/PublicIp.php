<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicIp extends Model
{
    protected $table = 'public_ips';

    public $timestamps = false;

    protected $fillable = ['ip', 'enabled', 'note'];

    public static function allowedIps(): array
    {
        return self::where('enabled', 1)->orderByRaw('INET_ATON(ip)')->pluck('ip')->toArray();
    }
}
