<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AroGroup extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'aro_groups';

    protected $fillable = ['name', 'parent_id', 'lft', 'rgt', 'value'];

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AroGroup::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AroGroup::class, 'parent_id');
    }

    // Users in this group
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'groups_aro_map', 'group_id', 'aro_id');
    }

    // ACL rules granted to this group
    public function aclRules(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Acl::class, 'aro_groups_map', 'group_id', 'acl_id');
    }
}
