<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    protected $table = 'meetings';

    protected $fillable = [
        'title', 'held_on', 'type', 'quorum_percent', 'status', 'comment',
    ];

    protected $casts = [
        'held_on'        => 'date',
        'quorum_percent' => 'integer',
    ];

    const STATUS_PLANNED = 'planned';
    const STATUS_OPEN    = 'open';
    const STATUS_CLOSED  = 'closed';

    public static function typeLabels(): array
    {
        return [
            'clenska_schuze' => 'Členská schůze',
            'nahradni'       => 'Náhradní schůze',
            'mimoradna'      => 'Mimořádná schůze',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_PLANNED => 'Plánovaná',
            self::STATUS_OPEN    => 'Probíhá',
            self::STATUS_CLOSED  => 'Uzavřená',
        ][$this->status] ?? $this->status;
    }

    public function attendances()
    {
        return $this->hasMany(MeetingAttendance::class);
    }
}
