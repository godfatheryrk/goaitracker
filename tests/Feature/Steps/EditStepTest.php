<?php

namespace Tests\Feature\Steps;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_body_updates_successfully(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => 'MIG and TIG basics',
        ]);

        $step = Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'body' => 'Original',
            'position' => 0,
        ]);

        $response = $this->actingAs($user)->patch(route('steps.update', [$venture, $step]), [
            'body' => 'Updated',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $step->refresh();
        $this->assertSame('Updated', $step->body);
        $this->assertSame(StepSource::AiInitial, $step->source);
    }

    public function test_source_stays_immutable_even_when_input_includes_source_field(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => 'MIG and TIG basics',
        ]);

        $step = Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'body' => 'Original',
            'position' => 0,
        ]);

        $response = $this->actingAs($user)->patch(route('steps.update', [$venture, $step]), [
            'body' => 'New body',
            'source' => 'manual',
        ]);

        $response->assertRedirect(route('ventures.show', $venture));

        $step->refresh();
        $this->assertSame('New body', $step->body);
        $this->assertSame(StepSource::AiInitial, $step->source);
    }

    public function test_user_b_gets_404_editing_user_a_step(): void
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
        ]);

        $response = $this->actingAs($userB)->patch(route('steps.update', [$aVenture, $aStep]), [
            'body' => 'evil',
        ]);

        $response->assertNotFound();

        $aStep->refresh();
        $this->assertSame('A original body', $aStep->body);
    }
}
