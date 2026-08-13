<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vlan extends Model
{
    use \App\Models\Concerns\Auditable;

    const DEFAULT_VLAN_TAG = 1;

    public $timestamps = false;
    protected $table = 'vlans';
    protected $fillable = ['name', 'tag_802_1q', 'comment'];
    protected $casts = ['tag_802_1q' => 'integer'];

    public function ifaces()
    {
        return $this->belongsToMany(Iface::class, 'ifaces_vlans', 'vlan_id', 'iface_id')
                    ->withPivot('tagged', 'port_vlan');
    }

    public function __toString(): string
    {
        return ($this->name ?? '') . ' (tag: ' . $this->tag_802_1q . ')';
    }
}
