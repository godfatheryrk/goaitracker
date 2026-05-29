<?php

namespace Tests\Feature\Ventures;

use App\Enums\StepSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves FR-005 (user can view a list of all their own ventures) in two shapes
 * plus the Step::$touches = ['venture'] wiring that makes the chosen
 * ORDER BY updated_at DESC sort honest. Without $touches the list lies
 * silently for any venture whose only recent activity is step edits.
 */
class ListVenturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_state_renders_create_cta(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('ventures.index'));

        $response->assertOk();
        $response->assertSeeText("haven't created any ventures yet");
        $response->assertSee(route('ventures.create'), false);
    }

    public function test_user_sees_only_their_own_ventures(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $userA->ventures()->create([
            'title' => "A's venture",
            'description' => null,
        ]);
        $userB->ventures()->create([
            'title' => "B's venture",
            'description' => null,
        ]);

        $response = $this->actingAs($userA)->get(route('ventures.index'));

        $response->assertOk();
        $response->assertSeeText("A's venture");
        $response->assertDontSeeText("B's venture");
    }

    public function test_step_save_touches_parent_venture_updated_at(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'V',
            'description' => null,
        ]);

        // Push updated_at back without triggering the touch behaviour.
        $past = now()->subDay()->startOfSecond();
        $venture->forceFill(['updated_at' => $past])->saveQuietly();
        $pushed = $venture->fresh()->updated_at;

        $venture->steps()->make([
            'body' => 'first step',
            'position' => 0,
        ])->forceFill([
            'owner_id' => $user->id,
            'source' => StepSource::Manual,
        ])->save();

        $this->assertTrue(
            $venture->fresh()->updated_at->greaterThan($pushed),
            'Expected Step::$touches = [\'venture\'] to advance the parent venture\'s updated_at on step save.'
        );
    }
}
