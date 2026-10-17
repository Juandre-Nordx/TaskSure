<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['draft_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Company policies and their acknowledgement history must be preserved.'));
    }

    public function currentVersion()
    {
        return $this->belongsTo(CompanyPolicyVersion::class, 'current_policy_version_id');
    }

    public function versions()
    {
        return $this->hasMany(CompanyPolicyVersion::class)->orderByDesc('version');
    }
}
