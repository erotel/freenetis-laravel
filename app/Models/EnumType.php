<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnumType extends Model
{
    public $timestamps = false;
    protected $table = 'enum_types';
    protected $fillable = ['type_id', 'value'];

    /** type_id grouping all contact types */
    const CONTACT_GROUP_ID = 4;
}
