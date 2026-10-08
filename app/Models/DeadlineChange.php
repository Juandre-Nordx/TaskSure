<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeadlineChange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['old_due_at' => 'datetime', 'new_due_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
