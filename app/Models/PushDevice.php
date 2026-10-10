<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;

class PushDevice extends Model
{
    protected $guarded = [];

    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    public function accessToken()
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
