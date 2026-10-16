<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

class MobileAccessToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected static function booted(): void
    {
        static::creating(function (MobileAccessToken $token) {
            if ($token->tokenable instanceof User) {
                $token->store_id ??= $token->tokenable->storeId();
            }
        });
    }
}
