<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use \App\Models\Concerns\Auditable;

    public $timestamps = false;
    protected $table = 'contacts';
    protected $fillable = ['type', 'value'];

    const TYPE_ICQ    = 18;
    const TYPE_JABBER = 19;
    const TYPE_EMAIL  = 20;
    const TYPE_PHONE  = 21;
    const TYPE_SKYPE  = 22;
    const TYPE_MSN    = 23;
    const TYPE_WEB    = 25;

    public function users()
    {
        return $this->belongsToMany(User::class, 'users_contacts', 'contact_id', 'user_id')
                    ->withPivot('mail_redirection');
    }

    public function enumType()
    {
        return $this->belongsTo(EnumType::class, 'type', 'id');
    }
}
