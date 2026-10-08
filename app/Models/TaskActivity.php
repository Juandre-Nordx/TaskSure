<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskActivity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
