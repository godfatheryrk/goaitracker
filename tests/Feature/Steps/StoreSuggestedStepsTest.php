<?php

namespace Tests\Feature\Steps;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use App\Models\Venture;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSuggestedStepsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a venture owned by $user seeded with 3 ai_initial steps at 0/1/2.
     */
    private function ventureWithThreeSteps(User $user): Venture
    {
        $venture = $user->ventures()->create([
            'title' => 'Renovate the bathroom',
            'description' => 'Tiles, plumbing, paint',
        ]);

        Step::factory()->count(3)->state(new Sequence(
            ['position' => 0],
            ['position' => 1],
            ['position' => 2],
        ))->create([
            'venture_id' => $venture->id,
            'owner_id' => $user->id,
            'source' => StepSource::AiInitial,
        ]);

        return $venture;
    }

    public function test_keeping_all_seven_appends_seven_ai_extension_rows_at_end_of_list(): void
    {
        $user = User::factory()->create();
        $venture = $this->ventureWithThreeSteps($user);

        $bodies = ['a', 'b', 'c', 'd', 'e', 'f', 'g'];
        $payload = ['suggestions' => []];
        foreach ($bodies as $body) {
            $payload['suggestions'][] = ['body' => $body, 'keep' => '1'];
        }

        $response = $this->actingAs($user)->post(route('steps.suggestions.store', $venture), $payload);

        $response->assertRedirect(route('ventures.show', $venture));
        $this->assertSame(10, $venture->steps()->count());

        $new = $venture->steps()->where('source', StepSource::AiExtension)->orderBy('position')->get();
        $this->assertCount(7, $new);

        foreach ($new as $i => $step) {
            $this->assertSame($bodies[$i], $step->body);
            $this->assertSame(3 + $i, $step->position);
            $this->assertSame(StepSource::AiExtension, $step->source);
            $this->assertSame($user->id, $step->owner_id);
        }
    }

    public function test_keeping_subset_persists_only_chosen_rows_in_order(): void
    {
        $user = User::factory()->create();
        $venture = $this->ventureWithThreeSteps($user);

        // keep rows 0, 2, 4 → bodies a / c / e
        $payload = ['suggestions' => [
            ['body' => 'a', 'keep' => '1'],
            ['body' => 'b'],
            ['body' => 'c', 'keep' => '1'],
            ['body' => 'd'],
            ['body' => 'e', 'keep' => '1'],
            ['body' => 'f'],
            ['body' => 'g'],
        ]];

        $response = $this->actingAs($user)->post(route('steps.suggestions.store', $venture), $payload);

        $response->assertRedirect(route('ventures.show', $venture));
        $this->assertSame(6, $venture->steps()->count());

        $new = $venture->steps()->where('source', StepSource::AiExtension)->orderBy('position')->get();
        $this->assertCount(3, $new);

        $expected = ['a', 'c', 'e'];
        foreach ($new as $i => $step) {
            $this->assertSame($expected[$i], $step->body);
            $this->assertSame(3 + $i, $step->position);
            $this->assertSame(StepSource::AiExtension, $step->source);
        }
    }

    public function test_keeping_none_persists_nothing_and_redirects(): void
    {
        $user = User::factory()->create();
        $venture = $this->ventureWithThreeSteps($user);

        $payload = ['suggestions' => [
            ['body' => 'a'],
            ['body' => 'b'],
            ['body' => 'c'],
            ['body' => 'd'],
            ['body' => 'e'],
            ['body' => 'f'],
            ['body' => 'g'],
        ]];

        $response = $this->actingAs($user)->post(route('steps.suggestions.store', $venture), $payload);

        $response->assertRedirect(route('ventures.show', $venture));
        $this->assertSame(3, $venture->steps()->count());
        $response->assertSessionMissing('ai_unavailable');
    }

    public function test_user_b_gets_404_on_user_a_confirm_endpoint(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $aVenture = $this->ventureWithThreeSteps($userA);

        $payload = ['suggestions' => [['body' => 'evil', 'keep' => '1']]];

        $response = $this->actingAs($userB)->post(route('steps.suggestions.store', $aVenture), $payload);

        $response->assertNotFound();
        $this->assertSame(3, $aVenture->steps()->count());
    }
}
