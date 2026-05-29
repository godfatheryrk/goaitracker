<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_is_removed(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => 'MIG basics',
        ]);
        $expense = Expense::factory()->for($venture, 'venture')->create([
            'owner_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->delete(route('expenses.destroy', [$venture, $expense]));

        $response->assertRedirect(route('ventures.show', $venture));
        $this->assertSame(0, Expense::count());
    }

    public function test_user_b_gets_404_deleting_user_a_expense(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aVenture = $userA->ventures()->create([
            'title' => "A's venture",
            'description' => 'secret',
        ]);
        $aExpense = Expense::factory()->for($aVenture, 'venture')->create([
            'owner_id' => $userA->id,
        ]);

        $response = $this->actingAs($userB)->delete(route('expenses.destroy', [$aVenture, $aExpense]));

        $response->assertNotFound();
        $this->assertSame(1, Expense::count());
        $this->assertNotNull($aExpense->fresh());
    }
}
