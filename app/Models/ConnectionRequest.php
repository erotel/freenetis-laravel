<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectionRequest extends Model
{
    public $timestamps = false;
    protected $table = 'connection_requests';
    protected $guarded = [];

    const STATE_UNDECIDED = 0;
    const STATE_REJECTED  = 1;
    const STATE_APPROVED  = 2;

    const STATE_LABELS = [
        self::STATE_UNDECIDED => 'Čekající',
        self::STATE_REJECTED  => 'Zamítnuto',
        self::STATE_APPROVED  => 'Schváleno',
    ];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function addedUser()
    {
        return $this->belongsTo(User::class, 'added_user_id');
    }

    public function decidedUser()
    {
        return $this->belongsTo(User::class, 'decided_user_id');
    }

    public function subnet()
    {
        return $this->belongsTo(Subnet::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceTemplate()
    {
        return $this->belongsTo(DeviceTemplate::class);
    }

    public function deviceType()
    {
        return $this->belongsTo(EnumType::class, 'device_type_id');
    }

    public function stateName(): string
    {
        return self::STATE_LABELS[$this->state] ?? (string) $this->state;
    }
}
