<?php

namespace Tests\Feature\Ventures;

use App\Enums\StepSource;
use App\Models\Step;
use App\Models\User;
use App\Models\Venture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves FR-007 (user can delete a venture, with confirmation) in two shapes:
 * happy path drops venture + step subtree via the schema cascade, and the
 * F-01 enforcement-checklist item 4 holds on the destroy endpoint (foreign
 * delete → 404, not 403; target row stays).
 */
class DeleteVentureTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_delete_their_own_venture(): void
    {
        $user = User::factory()->create();

        $target = $user->ventures()->create([
            'title' => 'target',
            'description' => null,
        ]);
        foreach (range(0, 2) as $i) {
            $target->steps()->make([
                'body' => "t step {$i}",
                'position' => $i,
            ])->forceFill([
                'owner_id' => $user->id,
                'source' => StepSource::Manual,
            ])->save();
        }

        $sibling = $user->ventures()->create([
            'title' => 'sibling',
            'description' => null,
        ]);
        foreach (range(0, 1) as $i) {
            $sibling->steps()->make([
                'body' => "s step {$i}",
                'position' => $i,
            ])->forceFill([
                'owner_id' => $user->id,
                'source' => StepSource::Manual,
            ])->save();
        }

        $targetId = $target->id;
        $targetStepIds = $target->steps->pluck('id')->all();
        $siblingId = $sibling->id;
        $siblingStepIds = $sibling->steps->pluck('id')->all();

        $response = $this->actingAs($user)
            ->delete(route('ventures.destroy', $target));

        $response->assertRedirect(route('ventures.index'));

        $this->assertNull(Venture::find($targetId));
        $this->assertSame(0, Step::whereIn('id', $targetStepIds)->count());

        $this->assertNotNull(Venture::find($siblingId));
        $this->assertSame(2, Step::whereIn('id', $siblingStepIds)->count());
    }

    public function test_user_b_gets_404_on_user_a_venture_destroy(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $venture = $userA->ventures()->create([
            'title' => "A's venture",
            'description' => null,
        ]);

        $response = $this->actingAs($userB)
            ->delete(route('ventures.destroy', $venture));

        $response->assertNotFound();
        $this->assertNotNull(Venture::find($venture->id));
    }
}
