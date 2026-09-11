<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingAttendance extends Model
{
    protected $table = 'meeting_attendances';

    protected $fillable = [
        'meeting_id', 'member_id', 'checked_in_at', 'proxy_holder_id', 'note',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];

    /** Přítomen fyzicky, nebo zastoupen plnou mocí = započítává se do kvóra. */
    public function counts(): bool
    {
        return $this->checked_in_at !== null || $this->proxy_holder_id !== null;
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function proxyHolder()
    {
        return $this->belongsTo(Member::class, 'proxy_holder_id');
    }
}
