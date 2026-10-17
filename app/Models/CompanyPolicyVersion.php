<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPolicyVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Published policy versions are immutable. Create a new draft.'));
        static::deleting(fn () => throw new \LogicException('Published policy versions must be preserved.'));
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    public function policy()
    {
        return $this->belongsTo(CompanyPolicy::class, 'company_policy_id');
    }

    public function acknowledgements()
    {
        return $this->hasMany(CompanyPolicyAcknowledgement::class, 'policy_version_id');
    }

    public function toPolicyData(): array
    {
        return $this->only(['id', 'version', 'title', 'content']) + [
            'policy_id' => (int) $this->company_policy_id,
            'published_at' => $this->published_at->toIso8601String(),
            'document' => $this->pdf_path ? [
                'name' => $this->pdf_name,
                'size' => (int) $this->pdf_size,
                'mime' => 'application/pdf',
                'url' => '/company-policy/versions/'.$this->id.'/pdf',
            ] : null,
        ];
    }
}
