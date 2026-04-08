<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailQueueAttachment extends Model
{
    protected $table = 'email_queue_attachments';
    protected $guarded = [];
    public $timestamps = false;
}
