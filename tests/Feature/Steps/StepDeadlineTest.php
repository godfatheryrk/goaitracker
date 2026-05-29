<?php

namespace Tests\Feature\Steps;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves FR-014 (optional per-step deadline) at the backend layer: persistence
 * on add, set/clear on edit, invalid-date rejection, and two-user-404 isolation
 * on the write path. View assertions live in the Phase 2 render tests.
 */
class StepDeadlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_add_step_persists_deadline(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => null,
        ]);

        $response = $this->actingAs($user)->post(route('steps.store', $venture), [
            'body' => 'Buy a welding mask',
            'deadline' => '2026-06-10',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $step = $venture->steps()->firstOrFail();
        $this->assertSame('2026-06-10', $step->deadline->format('Y-m-d'));
    }

    public function test_add_step_without_deadline_is_first_class(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => null,
        ]);

        $response = $this->actingAs($user)->post(route('steps.store', $venture), [
            'body' => 'A step with no deadline',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $step = $venture->steps()->firstOrFail();
        $this->assertNull($step->deadline);
    }

    public function test_edit_step_sets_deadline(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => null,
        ]);

        $step = Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'body' => 'Original',
            'position' => 0,
        ]);

        $response = $this->actingAs($user)->patch(route('steps.update', [$venture, $step]), [
            'body' => 'Original',
            'deadline' => '2026-07-01',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $step->refresh();
        $this->assertSame('2026-07-01', $step->deadline->format('Y-m-d'));
    }

    public function test_edit_step_with_empty_deadline_clears_it(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => null,
        ]);

        $step = Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'body' => 'Original',
            'position' => 0,
            'deadline' => '2026-06-10',
        ]);

        $response = $this->actingAs($user)->patch(route('steps.update', [$venture, $step]), [
            'body' => 'Original',
            'deadline' => '',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $step->refresh();
        $this->assertNull($step->deadline);
    }

    public function test_non_date_deadline_is_rejected(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => null,
        ]);

        $response = $this->actingAs($user)->post(route('steps.store', $venture), [
            'body' => 'A step',
            'deadline' => 'not-a-date',
        ]);

        $response->assertSessionHasErrors('deadline');
        $this->assertSame(0, $venture->steps()->count());
    }

    public function test_user_b_gets_404_setting_deadline_on_user_a_step(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aVenture = $userA->ventures()->create([
            'title' => "A's venture",
            'description' => null,
        ]);

        $aStep = Step::factory()->create([
            'venture_id' => $aVenture->id,
            'owner_id' => $userA->id,
            'source' => StepSource::AiInitial,
            'body' => 'A original body',
            'position' => 0,
            'deadline' => null,
        ]);

        $response = $this->actingAs($userB)->patch(route('steps.update', [$aVenture, $aStep]), [
            'body' => 'A original body',
            'deadline' => '2026-06-10',
        ]);

        $response->assertNotFound();

        $aStep->refresh();
        $this->assertNull($aStep->deadline);
    }
}
