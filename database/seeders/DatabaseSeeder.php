<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Restocking', 'Store presentation', 'Stock control', 'Deliveries', 'Opening & closing', 'Safety & compliance'] as $name) {
            Category::firstOrCreate(['name' => $name]);
        }
    }
}
