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

    public function test_venture_with_imminent_or_overdue_step_has_pressure_count(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Pressured venture',
            'description' => null,
        ]);

        // One due within the imminent window, one already overdue.
        $venture->steps()->make([
            'body' => 'Imminent step',
            'position' => 0,
            'deadline' => today()->addDay(),
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();

        $venture->steps()->make([
            'body' => 'Overdue step',
            'position' => 1,
            'deadline' => today()->subDay(),
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();

        $response = $this->actingAs($user)->get(route('ventures.index'));

        $listed = $response->viewData('ventures')->firstWhere('id', $venture->id);
        $this->assertSame(2, $listed->pressured_steps_count);
    }

    public function test_completed_no_deadline_and_far_future_steps_are_not_pressure(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Calm venture',
            'description' => null,
        ]);

        // Completed but overdue — excluded (FR-020 "persists until complete").
        $venture->steps()->make([
            'body' => 'Completed overdue',
            'position' => 0,
            'deadline' => today()->subDay(),
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();
        $venture->steps()->where('position', 0)->update(['is_completed' => true]);

        // No deadline — excluded.
        $venture->steps()->make([
            'body' => 'No deadline',
            'position' => 1,
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();

        // More than the imminent window out — excluded.
        $venture->steps()->make([
            'body' => 'Far future',
            'position' => 2,
            'deadline' => today()->addDays(10),
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();

        $response = $this->actingAs($user)->get(route('ventures.index'));

        $listed = $response->viewData('ventures')->firstWhere('id', $venture->id);
        $this->assertSame(0, $listed->pressured_steps_count);
    }

    public function test_list_renders_pressure_marker_when_pressured(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Pressured venture',
            'description' => null,
        ]);

        $venture->steps()->make([
            'body' => 'Imminent step',
            'position' => 0,
            'deadline' => today()->addDay(),
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();

        $response = $this->actingAs($user)->get(route('ventures.index'));

        $response->assertOk();
        $response->assertSeeText('Deadline pressure');
    }

    public function test_list_omits_pressure_marker_when_not_pressured(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Calm venture',
            'description' => null,
        ]);

        $venture->steps()->make([
            'body' => 'Far future step',
            'position' => 0,
            'deadline' => today()->addDays(10),
        ])->forceFill(['owner_id' => $user->id, 'source' => StepSource::Manual])->save();

        $response = $this->actingAs($user)->get(route('ventures.index'));

        $response->assertOk();
        $response->assertDontSeeText('Deadline pressure');
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
