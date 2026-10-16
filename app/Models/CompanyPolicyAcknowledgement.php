<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPolicyAcknowledgement extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Acknowledgement records are immutable.'));
        static::deleting(fn () => throw new \LogicException('Acknowledgement history must be preserved.'));
    }
}
