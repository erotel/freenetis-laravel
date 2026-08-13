<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberWhitelist extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'members_whitelists';

    protected $fillable = ['member_id', 'permanent', 'since', 'until', 'comment'];

    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function isActive(): bool
    {
        $today = now()->toDateString();
        return $this->since <= $today && ($this->until === '9999-12-31' || $this->until >= $today);
    }

    public function untilDisplay(): string
    {
        return $this->until === '9999-12-31' ? 'trvale' : $this->until;
    }
}
