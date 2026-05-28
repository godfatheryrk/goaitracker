<?php

namespace Tests\Feature\Ventures;

use App\Models\Step;
use App\Models\User;
use App\Models\Venture;
use App\Services\AiStepSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateVentureTest extends TestCase
{
    use RefreshDatabase;

    private function sevenSteps(): array
    {
        return ['Step 1', 'Step 2', 'Step 3', 'Step 4', 'Step 5', 'Step 6', 'Step 7'];
    }

    public function test_creating_a_venture_persists_seven_ai_initial_steps_on_success(): void
    {
        $expected = $this->sevenSteps();

        $this->mock(AiStepSuggester::class, function ($mock) use ($expected) {
            $mock->shouldReceive('suggestSteps')->once()->andReturn($expected);
        });

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/ventures', [
            'title' => 'Learn welding',
            'description' => 'MIG and TIG basics',
        ]);

        $this->assertSame(1, Venture::count());

        $venture = Venture::first();
        $this->assertSame($user->id, $venture->owner_id);
        $this->assertSame('Learn welding', $venture->title);
        $this->assertSame('MIG and TIG basics', $venture->description);

        $this->assertSame(7, $venture->steps()->count());

        foreach ($venture->steps as $i => $step) {
            $this->assertSame($expected[$i], $step->body);
            $this->assertSame($i, $step->position);
            $this->assertSame(Step::SOURCE_AI_INITIAL, $step->source);
            $this->assertSame($user->id, $step->owner_id);
            $this->assertFalse($step->is_completed);
        }

        $response->assertRedirect(route('ventures.show', $venture));
        $response->assertSessionMissing('ai_unavailable');
    }

    public function test_creating_a_venture_succeeds_with_empty_steps_on_ai_failure(): void
    {
        $this->mock(AiStepSuggester::class, function ($mock) {
            $mock->shouldReceive('suggestSteps')->once()->andReturn([]);
        });

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/ventures', [
            'title' => 'Throw a 30-person party',
            'description' => 'garden, summer evening',
        ]);

        $this->assertSame(1, Venture::count());

        $venture = Venture::first();
        $this->assertSame(0, $venture->steps()->count());

        $response->assertRedirect(route('ventures.show', $venture));
        $response->assertSessionHas('ai_unavailable');
    }

    public function test_validation_fails_when_title_is_missing(): void
    {
        $this->mock(AiStepSuggester::class, function ($mock) {
            $mock->shouldNotReceive('suggestSteps');
        });

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from('/dashboard')
            ->post('/ventures', ['description' => 'no title here']);

        $response->assertSessionHasErrors('title');
        $this->assertSame(0, Venture::count());
        $this->assertSame(0, Step::count());
    }

    public function test_guest_post_redirects_to_login(): void
    {
        $response = $this->post('/ventures', [
            'title' => 'Anything',
            'description' => 'irrelevant',
        ]);

        $response->assertRedirect('/login');
        $this->assertSame(0, Venture::count());
    }
}
