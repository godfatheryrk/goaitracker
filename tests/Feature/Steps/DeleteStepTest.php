<?php

namespace Tests\Feature\Steps;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_is_removed(): void
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
            'position' => 0,
        ]);

        $response = $this->actingAs($user)->delete(route('steps.destroy', [$venture, $step]));

        $response->assertRedirect(route('ventures.show', $venture));
        $this->assertSame(0, Step::count());
    }

    public function test_user_b_gets_404_deleting_user_a_step(): void
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
            'position' => 0,
        ]);

        $response = $this->actingAs($userB)->delete(route('steps.destroy', [$aVenture, $aStep]));

        $response->assertNotFound();
        $this->assertTrue(Step::whereKey($aStep->id)->exists());
    }
}
