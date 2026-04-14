<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogQueue extends Model
{
    public $timestamps = false;
    protected $table = 'log_queues';

    const TYPE_FATAL_ERROR = 0;
    const TYPE_ERROR       = 1;
    const TYPE_WARNING     = 2;
    const TYPE_INFO        = 3;

    const STATE_NEW    = 0;
    const STATE_CLOSED = 1;

    protected $fillable = [
        'type', 'state', 'created_at', 'closed_by_user_id',
        'closed_at', 'description', 'exception_backtrace', 'comments_thread_id',
    ];

    public static function typeNames(): array
    {
        return [
            self::TYPE_FATAL_ERROR => 'Fatal error',
            self::TYPE_ERROR       => 'Error',
            self::TYPE_WARNING     => 'Warning',
            self::TYPE_INFO        => 'Information',
        ];
    }

    public static function typeColors(): array
    {
        return [
            self::TYPE_FATAL_ERROR => '#792020',
            self::TYPE_ERROR       => '#cd0000',
            self::TYPE_WARNING     => '#ff7800',
            self::TYPE_INFO        => '#1e84db',
        ];
    }

    public static function stateNames(): array
    {
        return [
            self::STATE_NEW    => 'Nový',
            self::STATE_CLOSED => 'Uzavřený',
        ];
    }

    public function typeName(): string
    {
        return self::typeNames()[$this->type] ?? 'Unknown';
    }

    public function typeColor(): string
    {
        return self::typeColors()[$this->type] ?? '#888888';
    }

    public function stateName(): string
    {
        return self::stateNames()[$this->state] ?? 'Unknown';
    }

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }
}
