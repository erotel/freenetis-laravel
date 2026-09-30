<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Výjimka z izolace klientů: člen smí ze zákaznického segmentu do daného cíle
 * ve vnitřní síti (jedna IP nebo CIDR). Viz {@see \App\Services\LanIsolationService}.
 */
class MemberLanAccess extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $table = 'member_lan_access';

    protected $fillable = ['member_id', 'destination', 'comment', 'created_by'];

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
