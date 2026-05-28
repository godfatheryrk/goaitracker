<?php

namespace Tests\Feature\Ai;

use App\Models\AiCallCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiCallCounterIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_b_cannot_see_user_a_counter_via_relationship(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        AiCallCounter::create([
            'owner_id' => $userA->id,
            'day' => today(),
            'count' => 3,
        ]);

        $this->assertSame(0, $userB->aiCallCounters()->count());
        $this->assertSame(1, $userA->aiCallCounters()->count());
    }

    public function test_deleting_user_cascades_to_their_counters(): void
    {
        $userA = User::factory()->create();

        AiCallCounter::create([
            'owner_id' => $userA->id,
            'day' => today(),
            'count' => 5,
        ]);

        $this->assertDatabaseHas('ai_call_counters', ['owner_id' => $userA->id]);

        $userA->delete();

        $this->assertDatabaseMissing('ai_call_counters', ['owner_id' => $userA->id]);
    }
}
