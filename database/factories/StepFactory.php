<?php

namespace Database\Factories;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\Venture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Step>
 */
class StepFactory extends Factory
{
    public function definition(): array
    {
        $venture = Venture::factory()->create();

        return [
            'venture_id' => $venture->id,
            'owner_id' => $venture->owner_id,
            'body' => fake()->sentence(),
            'is_completed' => false,
            'source' => StepSource::Manual,
            'position' => 0,
        ];
    }
}
