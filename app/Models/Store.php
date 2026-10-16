<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = ['name', 'company_id', 'owner_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'company_id' => 'integer', 'owner_id' => 'integer'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('active');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function data(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'company_id' => $this->company_id, 'company_name' => $this->company->name, 'active' => $this->active];
    }
}
