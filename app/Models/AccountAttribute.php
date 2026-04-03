<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountAttribute extends Model
{
    public $timestamps = false;
    protected $table = 'account_attributes';

    /** account_attribute_id for project accounts */
    const PROJECT_ATTRIBUTE_ID = 221103;
}
