<?php

namespace Tests\Feature\Ai;

use App\Ai\StepSuggestionAgent;
use App\Models\AiCallCounter;
use App\Models\User;
use App\Services\AiStepSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiStepSuggesterTest extends TestCase
{
    use RefreshDatabase;

    private function sevenSteps(): array
    {
        return ['Step 1', 'Step 2', 'Step 3', 'Step 4', 'Step 5', 'Step 6', 'Step 7'];
    }

    private function fakeSuccess(): void
    {
        StepSuggestionAgent::fake([json_encode(['steps' => $this->sevenSteps()])]);
    }

    private function service(): AiStepSuggester
    {
        return app(AiStepSuggester::class);
    }

    private function counterFor(User $user): int
    {
        return $user->aiCallCounters()->where('day', today())->value('count') ?? 0;
    }

    // ── success ─────────────────────────────────────────────────────────────

    public function test_success_returns_seven_strings_and_increments_counter(): void
    {
        $this->fakeSuccess();
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'Learn welding', 'Renovate bathroom');

        $this->assertSame($this->sevenSteps(), $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    // ── graceful failures ────────────────────────────────────────────────────

    public function test_fail_timeout_returns_empty_and_increments_counter(): void
    {
        StepSuggestionAgent::fake([fn () => throw new \RuntimeException('Connection timeout')]);
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    public function test_fail_4xx_returns_empty_and_increments_counter(): void
    {
        StepSuggestionAgent::fake([fn () => throw new \RuntimeException('401 Unauthorized')]);
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    public function test_fail_5xx_returns_empty_and_increments_counter(): void
    {
        StepSuggestionAgent::fake([fn () => throw new \RuntimeException('503 Service Unavailable')]);
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    public function test_fail_malformed_response_returns_empty_and_increments_counter(): void
    {
        StepSuggestionAgent::fake(['this is not valid json at all']);
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    public function test_fail_wrong_step_count_returns_empty_and_increments_counter(): void
    {
        StepSuggestionAgent::fake([json_encode(['steps' => ['Only one step']])]);
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    public function test_fail_throwable_returns_empty_and_increments_counter(): void
    {
        // Any SDK throwable maps to []; counter still increments (attempt-based).
        // The real missing-AI_API_KEY path is covered by manual smoke check 3.6.
        StepSuggestionAgent::fake([fn () => throw new \RuntimeException('API key not provided')]);
        $user = User::factory()->create();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(1, $this->counterFor($user));
    }

    // ── ceiling enforcement ──────────────────────────────────────────────────

    public function test_ceiling_blocks_call_and_does_not_increment_counter(): void
    {
        $user = User::factory()->create();

        AiCallCounter::create([
            'owner_id' => $user->id,
            'day' => today(),
            'count' => config('ai.step_suggestion.ceiling_per_day'),
        ]);

        StepSuggestionAgent::fake([])->preventStrayPrompts();

        $result = $this->service()->suggestSteps($user, 'T', 'D');

        $this->assertSame([], $result);
        $this->assertSame(config('ai.step_suggestion.ceiling_per_day'), $this->counterFor($user));
        StepSuggestionAgent::assertNeverPrompted();
    }

    // ── isolation: user A's exhausted ceiling does not affect user B ─────────

    public function test_isolation_user_a_ceiling_does_not_block_user_b(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        AiCallCounter::create([
            'owner_id' => $userA->id,
            'day' => today(),
            'count' => config('ai.step_suggestion.ceiling_per_day'),
        ]);

        StepSuggestionAgent::fake([json_encode(['steps' => $this->sevenSteps()])]);

        $result = $this->service()->suggestSteps($userB, 'Learn welding', 'Renovate bathroom');

        $this->assertSame($this->sevenSteps(), $result);
        $this->assertSame(1, $this->counterFor($userB));
        $this->assertSame(config('ai.step_suggestion.ceiling_per_day'), $this->counterFor($userA));
    }
}
