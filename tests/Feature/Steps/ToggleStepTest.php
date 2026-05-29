<?php

namespace Tests\Feature\Steps;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToggleStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_flips_and_returns_json_for_ajax(): void
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
            'is_completed' => false,
        ]);

        $response = $this->actingAs($user)
            ->patchJson(route('steps.completion', [$venture, $step]));

        $response->assertOk();
        $response->assertExactJson([
            'is_completed' => true,
            'completed' => 1,
            'total' => 1,
        ]);

        $step->refresh();
        $this->assertTrue($step->is_completed);
    }

    public function test_user_b_gets_404_toggling_user_a_step(): void
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
            'is_completed' => false,
        ]);

        $response = $this->actingAs($userB)
            ->patchJson(route('steps.completion', [$aVenture, $aStep]));

        $response->assertNotFound();

        $aStep->refresh();
        $this->assertFalse($aStep->is_completed);
    }
}
