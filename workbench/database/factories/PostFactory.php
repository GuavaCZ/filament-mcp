<?php

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Post;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'content' => fake()->paragraphs(2, true),
            'status' => fake()->randomElement(['draft', 'published']),
            'is_featured' => fake()->boolean(20),
            'published_at' => fake()->boolean(50) ? fake()->dateTimeThisYear() : null,
            'internal_notes' => fake()->sentence(),
            'admin_note' => fake()->sentence(),
        ];
    }
}
