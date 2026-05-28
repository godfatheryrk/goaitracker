<?php

namespace Tests\Feature\Ventures;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the F-01 S-01 enforcement checklist item 4 (two-user 404 not 403)
 * and item 5 (ownership-scoped binding) against the live venture surface.
 * See docs/reference/contract-surfaces.md#s-01-enforcement-checklist.
 */
class VentureIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_b_gets_404_on_user_a_venture_show(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $venture = $userA->ventures()->create([
            'title' => "A's private venture",
            'description' => 'secret',
        ]);

        // 404 (not 403) — the relationship-scoped query simply finds no row,
        // so user B cannot distinguish "exists but forbidden" from "does not exist".
        $this->actingAs($userB)
            ->get(route('ventures.show', $venture))
            ->assertNotFound();
    }

    public function test_guest_gets_redirect_on_venture_show(): void
    {
        $userA = User::factory()->create();
        $venture = $userA->ventures()->create([
            'title' => 'A',
            'description' => null,
        ]);

        $this->get(route('ventures.show', $venture))->assertRedirect('/login');
    }
}
