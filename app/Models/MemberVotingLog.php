<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit udělení/odebrání hlasovacího práva (members.can_vote). Degradace podle
 * pravidla „3× po sobě nepřítomen na schůzi" odkazuje na spouštějící meeting_id.
 */
class MemberVotingLog extends Model
{
    protected $table = 'member_voting_logs';

    public $timestamps = false;

    protected $fillable = [
        'member_id', 'action', 'meeting_id', 'reason', 'user_id', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    const ACTION_GRANTED  = 'granted';
    const ACTION_DEGRADED = 'degraded';
}
