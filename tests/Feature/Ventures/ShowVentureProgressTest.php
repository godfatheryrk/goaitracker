<?php

namespace Tests\Feature\Ventures;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowVentureProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_text_renders_correctly_for_mixed_completion(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => null,
        ]);

        Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'position' => 0,
            'is_completed' => true,
        ]);
        Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'position' => 1,
            'is_completed' => true,
        ]);
        Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'position' => 2,
            'is_completed' => false,
        ]);
        Step::factory()->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
            'position' => 3,
            'is_completed' => false,
        ]);

        $response = $this->actingAs($user)->get(route('ventures.show', $venture));

        $response->assertOk();
        $response->assertSeeText('2 of 4 completed');
    }
}
