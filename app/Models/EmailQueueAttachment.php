<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailQueueAttachment extends Model
{
    protected $table = 'email_queue_attachments';
    protected $fillable = ['email_queue_id', 'path', 'name', 'mime', 'inline', 'created_at'];
    public $timestamps = false;
}
