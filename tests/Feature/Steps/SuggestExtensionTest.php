<?php

namespace Tests\Feature\Steps;

use App\Models\AiCallCounter;
use App\Models\Step;
use App\Models\User;
use App\Services\AiStepSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuggestExtensionTest extends TestCase
{
    use RefreshDatabase;

    private function sevenSteps(): array
    {
        return ['step1', 'step2', 'step3', 'step4', 'step5', 'step6', 'step7'];
    }

    public function test_happy_path_renders_preview_with_seven_candidates(): void
    {
        $expected = $this->sevenSteps();
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Learn welding',
            'description' => 'MIG and TIG basics',
        ]);

        $this->mock(AiStepSuggester::class, function ($mock) use ($expected) {
            $mock->shouldReceive('suggestSteps')->once()->andReturn($expected);
        });

        $response = $this->actingAs($user)->post(route('steps.suggestions.preview', $venture));

        $response->assertOk();
        $response->assertViewIs('steps.suggestions.preview');
        $response->assertViewHas('suggestions', $expected);

        // 7 hidden body fields + 7 checked keep checkboxes rendered.
        for ($i = 0; $i < 7; $i++) {
            $response->assertSee('name="suggestions['.$i.'][body]"', false);
            $response->assertSee('name="suggestions['.$i.'][keep]"', false);
        }
    }

    public function test_ai_unavailable_flashes_and_redirects(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create([
            'title' => 'Throw a party',
            'description' => 'garden, summer evening',
        ]);

        $this->mock(AiStepSuggester::class, function ($mock) {
            $mock->shouldReceive('suggestSteps')->once()->andReturn([]);
        });

        $before = Step::count();

        $response = $this->actingAs($user)->post(route('steps.suggestions.preview', $venture));

        $response->assertRedirect(route('ventures.show', $venture));
        $this->assertStringContainsString("couldn't suggest more steps", session('ai_unavailable'));
        $this->assertSame($before, Step::count());
    }

    public function test_user_b_gets_404_on_user_a_suggest_endpoint_and_suggester_not_called(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $aVenture = $userA->ventures()->create([
            'title' => "A's private venture",
            'description' => 'secret',
        ]);

        $this->mock(AiStepSuggester::class, function ($mock) {
            $mock->shouldReceive('suggestSteps')->never();
        });

        $response = $this->actingAs($userB)->post(route('steps.suggestions.preview', $aVenture));

        $response->assertNotFound();
        $this->assertSame(0, AiCallCounter::where('owner_id', $userB->id)->count());
    }

    public function test_guest_post_to_suggest_endpoint_redirects_to_login(): void
    {
        $response = $this->post(route('steps.suggestions.preview', 1));

        $response->assertRedirect('/login');
    }
}
