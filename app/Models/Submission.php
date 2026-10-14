<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    protected $guarded = [];

    protected $attributes = ['review_status' => 'not_reviewed'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'due_at' => 'datetime', 'reviewed_at' => 'datetime', 'started_at' => 'datetime'];
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviewLabel(): string
    {
        return match ($this->review_status) {
            'approved' => 'Historical approval',
            'changes_requested' => 'Historical correction',
            'cancelled' => 'Cancelled attempt',
            default => 'Completion recorded',
        };
    }
}
