<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $guarded = [];

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
