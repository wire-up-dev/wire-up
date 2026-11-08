<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\Mediable;
use App\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mediable>
 */
final class MediableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'media_id' => Media::factory(),
            'mediable_id' => Page::factory(),
            'mediable_type' => new Page()->getMorphClass(),
            'locale' => 'en',
            'role' => fake()->randomElement(['poster', 'banner', 'thumbnail']),
            'crop' => [
                'desktop' => [
                    'x' => fake()->numberBetween(0, 100),
                    'y' => fake()->numberBetween(0, 100),
                    'w' => fake()->numberBetween(100, 800),
                    'h' => fake()->numberBetween(100, 600),
                ],
                'mobile' => [
                    'x' => fake()->numberBetween(0, 100),
                    'y' => fake()->numberBetween(0, 100),
                    'w' => fake()->numberBetween(100, 800),
                    'h' => fake()->numberBetween(100, 600),
                ],
            ],
            'metadata' => null,
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
