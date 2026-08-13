<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Acl extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'acl';
    protected $fillable = ['note', 'allow', 'enabled'];

    // What permissions this rule grants (via axo_map → axo)
    public function permissions()
    {
        return $this->belongsToMany(Axo::class, 'axo_map', 'acl_id', 'axo_id');
    }

    // ACO (action) for this rule
    public function acoMap()
    {
        return $this->hasMany(AcoMap::class, 'acl_id');
    }
}
