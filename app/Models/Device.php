<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    public $timestamps = false;
    protected $table = 'devices';
    protected $fillable = [
        'user_id', 'address_point_id', 'name', 'type', 'trade_name',
        'operating_system', 'PPPoE_logging_in', 'login', 'password',
        'access_time', 'price', 'payment_rate', 'buy_date', 'comment',
    ];
    protected $casts = ['type' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function addressPoint()
    {
        return $this->belongsTo(AddressPoint::class);
    }

    public function ifaces()
    {
        return $this->hasMany(Iface::class);
    }

    public function deviceAdmins()
    {
        return $this->hasMany(DeviceAdmin::class);
    }

    public function deviceEngineers()
    {
        return $this->hasMany(DeviceEngineer::class);
    }

    public function enumType()
    {
        return $this->belongsTo(EnumType::class, 'type', 'id');
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
