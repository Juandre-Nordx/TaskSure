<?php

namespace App\Models;

use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Model;

class AccountAudit extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (AccountAudit $audit) {
            $audit->store_id ??= app(StoreContext::class)->store?->id;
            $audit->company_id ??= app(StoreContext::class)->store?->company_id;
        });
    }

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
