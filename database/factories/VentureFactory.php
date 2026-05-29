<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Venture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venture>
 */
class VentureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}
