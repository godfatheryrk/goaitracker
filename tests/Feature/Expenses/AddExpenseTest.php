<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_persists_with_owner_and_venture_set(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Renovate the bathroom',
            'description' => 'Tiles, plumbing, paint',
        ]);

        $response = $this->actingAs($user)->post(route('expenses.store', $venture), [
            'amount' => '12.50',
            'description' => 'Coffee',
            'date' => '2026-05-15',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $this->assertSame(1, $venture->expenses()->count());

        $expense = $venture->expenses()->first();
        $this->assertNotNull($expense);
        $this->assertSame($user->id, $expense->owner_id);
        $this->assertSame($venture->id, $expense->venture_id);
        $this->assertSame('12.50', $expense->amount);
        $this->assertSame('Coffee', $expense->description);
        $this->assertSame('2026-05-15', $expense->date->toDateString());
    }

    public function test_user_b_gets_404_adding_expense_to_user_a_venture(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aVenture = $userA->ventures()->create([
            'title' => "A's private venture",
            'description' => 'secret',
        ]);

        $response = $this->actingAs($userB)->post(route('expenses.store', $aVenture), [
            'amount' => '99.00',
            'description' => 'evil',
            'date' => '2026-05-15',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, Expense::count());
    }
}
