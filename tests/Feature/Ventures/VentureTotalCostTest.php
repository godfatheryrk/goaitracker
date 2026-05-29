<?php

namespace Tests\Feature\Ventures;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentureTotalCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_cost_renders_on_detail_and_list(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Ship a side project',
            'description' => 'MVP in 3 weeks',
        ]);

        Expense::factory()->count(3)->for($venture, 'venture')->state(new Sequence(
            ['amount' => '12.50'],
            ['amount' => '7.25'],
            ['amount' => '5.00'],
        ))->create([
            'owner_id' => $user->id,
        ]);

        // Sum: 12.50 + 7.25 + 5.00 = 24.75
        $this->actingAs($user)
            ->get(route('ventures.show', $venture))
            ->assertOk()
            ->assertSee('Total: 24.75');

        $this->actingAs($user)
            ->get(route('ventures.index'))
            ->assertOk()
            ->assertSee('Total: 24.75');
    }
}
