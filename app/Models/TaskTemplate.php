<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['required_evidence' => 'array', 'active' => 'boolean', 'next_date' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
