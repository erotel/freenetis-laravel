<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEngineer extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'device_engineers';
    protected $fillable = ['device_id', 'user_id'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
