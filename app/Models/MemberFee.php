<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberFee extends Model
{
    public $timestamps = false;
    protected $table = 'members_fees';
    protected $fillable = ['fee_id', 'member_id', 'activation_date', 'deactivation_date', 'priority', 'comment'];

    protected $casts = [
        'activation_date'   => 'date',
        'deactivation_date' => 'date',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function fee()
    {
        return $this->belongsTo(Fee::class);
    }

    public function scopeActive($query)
    {
        return $query
            ->whereDate('activation_date', '<=', today())
            ->where(function ($q) {
                $q->whereNull('deactivation_date')
                  ->orWhereDate('deactivation_date', '>=', today());
            });
    }
}
