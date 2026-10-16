<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Company extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (Company $company) {
            if (Schema::hasTable('stores')) {
                $company->stores()->create(['name' => mb_substr($company->name.' store', 0, 120), 'active' => true]);
            }
        });
    }

    public function stores()
    {
        return $this->hasMany(Store::class);
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'policy_draft_revision' => 'integer'];
    }

    public function currentPolicy()
    {
        return $this->belongsTo(CompanyPolicyVersion::class, 'current_policy_version_id');
    }

    public function policyVersions()
    {
        return $this->hasMany(CompanyPolicyVersion::class)->orderByDesc('version');
    }
}
