<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Venture;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        $venture = Venture::factory()->create();

        return [
            'venture_id' => $venture->id,
            'owner_id' => $venture->owner_id,
            'amount' => $this->faker->randomFloat(2, 0, 9999.99),
            'description' => $this->faker->sentence(3),
            'date' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
        ];
    }
}
