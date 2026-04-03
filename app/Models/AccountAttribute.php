<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountAttribute extends Model
{
    public $timestamps = false;
    protected $table = 'account_attributes';

    /** account_attribute_id for member credit accounts ("Účet kreditu") */
    const CREDIT_ATTRIBUTE_ID = 221100;

    /** account_attribute_id for project accounts */
    const PROJECT_ATTRIBUTE_ID = 221103;
}
