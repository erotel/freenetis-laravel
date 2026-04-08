<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailQueue extends Model
{
    protected $table = 'email_queues';
    protected $guarded = [];
    public $timestamps = false;

    const STATE_NEW    = 0;
    const STATE_SENT   = 1;
    const STATE_FAILED = 2;

    public function attachments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EmailQueueAttachment::class, 'email_queue_id');
    }
}
