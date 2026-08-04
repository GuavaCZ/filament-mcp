<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Category;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // The panels auto-authenticate as this user, see AutoLogin middleware.
        User::factory()->admin()->create([
            'name' => 'Workbench User',
            'email' => 'workbench@example.com',
        ]);

        Category::factory()
            ->count(3)
            ->has(Post::factory()->count(5))
            ->create()
        ;
    }
}
