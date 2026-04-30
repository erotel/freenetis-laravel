<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmtpException extends Model
{
    protected $table = 'smtp_exceptions';

    public $timestamps = false;

    protected $fillable = [
        'intip',
        'user',
        'datum',
    ];

    protected $casts = [
        'datum' => 'date',
    ];
}
