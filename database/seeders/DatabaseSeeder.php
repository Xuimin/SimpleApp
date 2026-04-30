<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        Category::factory()->create([
            'name' => 'Item',
        ]);

        Category::factory()->create([
            'name' => 'Food',
        ]);

        Category::factory()->create([
            'name' => 'Equipment',
        ]);
    }
}
