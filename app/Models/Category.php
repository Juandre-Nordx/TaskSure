<?php

namespace App\Models;

use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::created(function (Category $category) {
            $context = app(StoreContext::class);
            $id = $context->store?->id ?? Store::whereHas('company', fn ($q) => $q->where('is_primary', true))->orderBy('id')->value('id');
            if ($id) {
                $category->stores()->syncWithoutDetaching([$id]);
            }
        });
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class);
    }

    public function scopeForStore($query, User $user): void
    {
        $query->whereHas('stores', fn ($q) => $q->where('stores.id', $user->storeId()));
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
