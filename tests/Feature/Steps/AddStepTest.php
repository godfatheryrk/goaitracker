<?php

namespace Tests\Feature\Steps;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_step_persists_at_end_of_list_with_source_manual(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Renovate the bathroom',
            'description' => 'Tiles, plumbing, paint',
        ]);

        // Seed three steps at positions 0/1/2 so the new step lands at 3.
        Step::factory()->count(3)->state(new Sequence(
            ['position' => 0],
            ['position' => 1],
            ['position' => 2],
        ))->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
        ]);

        $response = $this->actingAs($user)->post(route('steps.store', $venture), [
            'body' => 'Manual addition',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $this->assertSame(4, $venture->steps()->count());

        $newStep = $venture->steps()->where('body', 'Manual addition')->first();
        $this->assertNotNull($newStep);
        $this->assertSame(StepSource::Manual, $newStep->source);
        $this->assertSame(3, $newStep->position);
        $this->assertSame($user->id, $newStep->owner_id);
        $this->assertFalse($newStep->is_completed);
    }

    public function test_user_b_gets_404_adding_step_to_user_a_venture(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aVenture = $userA->ventures()->create([
            'title' => "A's private venture",
            'description' => 'secret',
        ]);

        $before = Step::count();

        $response = $this->actingAs($userB)->post(route('steps.store', $aVenture), [
            'body' => 'evil',
        ]);

        $response->assertNotFound();
        $this->assertSame($before, Step::count());
    }
}
