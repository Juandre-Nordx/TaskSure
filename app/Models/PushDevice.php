<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class PushDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    protected static function booted(): void
    {
        static::creating(function (PushDevice $device) {
            $device->store_id ??= $device->accessToken?->store_id;
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'company_id' => 'integer', 'token' => 'encrypted'];
    }

    public function accessToken()
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
