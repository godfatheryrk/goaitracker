<?php

namespace Tests\Feature\Ventures;

use App\Enums\StepSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the FR-020 detail-view badge surface: imminent → amber, overdue → red
 * "Overdue", future/no-deadline → no emphasis/absent, and a completed overdue
 * step carries the would-be pressure class in data-pressure-class (for the live
 * un-complete restore) WITHOUT applying it to the rendered class attribute.
 */
class ShowVentureDeadlineTest extends TestCase
{
    use RefreshDatabase;

    private function makeStep(User $user, $venture, array $attrs): void
    {
        $venture->steps()->make(array_merge([
            'body' => 'A step',
            'position' => 0,
        ], $attrs))->forceFill([
            'owner_id' => $user->id,
            'source' => StepSource::Manual,
        ])->save();
    }

    public function test_imminent_step_renders_amber_emphasis(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create(['title' => 'V', 'description' => null]);
        $this->makeStep($user, $venture, ['deadline' => today()->addDay()]);

        $response = $this->actingAs($user)->get(route('ventures.show', $venture));

        $response->assertOk();
        $response->assertSee('data-deadline-badge', false);
        $response->assertSee('text-warning', false);
    }

    public function test_overdue_step_renders_red_overdue_emphasis(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create(['title' => 'V', 'description' => null]);
        $this->makeStep($user, $venture, ['deadline' => today()->subDay()]);

        $response = $this->actingAs($user)->get(route('ventures.show', $venture));

        $response->assertOk();
        $response->assertSeeText('Overdue');
        $response->assertSee('text-xs text-base-content/60 text-error font-medium', false);
    }

    public function test_step_without_deadline_renders_no_badge(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create(['title' => 'V', 'description' => null]);
        $this->makeStep($user, $venture, ['deadline' => null]);

        $response = $this->actingAs($user)->get(route('ventures.show', $venture));

        $response->assertOk();
        $response->assertDontSee('data-deadline-badge', false);
    }

    public function test_far_future_step_renders_muted_no_emphasis(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create(['title' => 'V', 'description' => null]);
        $this->makeStep($user, $venture, ['deadline' => today()->addDays(30)]);

        $response = $this->actingAs($user)->get(route('ventures.show', $venture));

        $response->assertOk();
        $response->assertSee('data-deadline-badge', false);
        // Non-pressured: data-pressure-class is empty and no emphasis applied to the badge.
        $response->assertSee('data-pressure-class=""', false);
        $response->assertDontSee('text-warning', false);
        // Badge-specific (the page's Delete button legitimately uses text-error).
        $response->assertDontSee('text-xs text-base-content/60 text-error', false);
    }

    public function test_completed_overdue_step_renders_no_applied_emphasis_but_keeps_data_class(): void
    {
        $user = User::factory()->create();
        $venture = $user->ventures()->create(['title' => 'V', 'description' => null]);
        $this->makeStep($user, $venture, ['deadline' => today()->subDay()]);
        $venture->steps()->where('position', 0)->update(['is_completed' => true]);

        $response = $this->actingAs($user)->get(route('ventures.show', $venture));

        $response->assertOk();
        // The would-be pressure class survives in the data attribute (for the JS un-complete restore)...
        $response->assertSee('data-pressure-class="text-error font-medium"', false);
        // ...but it is NOT applied to the visible class attribute on a completed step.
        $response->assertDontSee('text-xs text-base-content/60 text-error font-medium', false);
    }
}
