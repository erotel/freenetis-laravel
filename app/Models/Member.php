<?php

namespace App\Models;

use App\Helpers\MemberType;
use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    public $timestamps = false;
    protected $table = 'members';

    protected $fillable = [
        'name',
        'user_id',
        'address_point_id',
        'type',
        'registration',
        'organization_identifier',
        'vat_organization_identifier',
        'entrance_fee',
        'debt_payment_rate',
        'entrance_date',
        'leaving_date',
        'comment',
        'locked',
    ];

    const ASSOCIATION = 1;

    protected $casts = [
        'type'         => 'integer',
        'registration' => 'boolean',
        'locked'       => 'boolean',
    ];

    public static function typeLabels(): array
    {
        return MemberType::labels();
    }

    public function getTypeLabelAttribute(): string
    {
        return MemberType::label((int) $this->type);
    }

    // --- Relations ---

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function primaryUser()
    {
        return $this->hasOne(User::class)->where('type', User::MAIN_USER);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function addressPoint()
    {
        return $this->belongsTo(AddressPoint::class);
    }

    public function ipAddresses()
    {
        return $this->hasMany(IpAddress::class);
    }

    // --- Helpers ---

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
