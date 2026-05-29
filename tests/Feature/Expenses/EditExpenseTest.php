<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditExpenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_updates_successfully(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Throw a party',
            'description' => '30 people',
        ]);
        $expense = Expense::factory()->for($venture, 'venture')->create([
            'owner_id' => $user->id,
            'amount' => '5.00',
            'description' => 'Old',
            'date' => '2026-04-01',
        ]);

        $response = $this->actingAs($user)->patch(route('expenses.update', [$venture, $expense]), [
            'amount' => '8.25',
            'description' => 'New',
            'date' => '2026-05-15',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $expense->refresh();
        $this->assertSame('8.25', $expense->amount);
        $this->assertSame('New', $expense->description);
        $this->assertSame('2026-05-15', $expense->date->toDateString());
        $this->assertSame($user->id, $expense->owner_id);
        $this->assertSame($venture->id, $expense->venture_id);
    }

    public function test_user_b_gets_404_editing_user_a_expense(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aVenture = $userA->ventures()->create([
            'title' => "A's venture",
            'description' => 'secret',
        ]);
        $aExpense = Expense::factory()->for($aVenture, 'venture')->create([
            'owner_id' => $userA->id,
            'amount' => '5.00',
            'description' => 'Old',
            'date' => '2026-04-01',
        ]);

        $response = $this->actingAs($userB)->patch(route('expenses.update', [$aVenture, $aExpense]), [
            'amount' => '8.25',
            'description' => 'New',
            'date' => '2026-05-15',
        ]);

        $response->assertNotFound();

        $aExpense->refresh();
        $this->assertSame('5.00', $aExpense->amount);
        $this->assertSame('Old', $aExpense->description);
        $this->assertSame('2026-04-01', $aExpense->date->toDateString());
        $this->assertSame($userA->id, $aExpense->owner_id);
    }
}
