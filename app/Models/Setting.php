<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    public $timestamps = false;
    protected $table      = 'config';
    protected $primaryKey = 'name';
    public    $incrementing = false;
    protected $keyType    = 'string';
    protected $fillable   = ['name', 'value'];

    public static function get(string $name, mixed $default = null): mixed
    {
        $row = static::find($name);
        return $row ? $row->value : $default;
    }

    public static function set(string $name, mixed $value): void
    {
        static::updateOrCreate(['name' => $name], ['value' => (string) $value]);
    }
}
