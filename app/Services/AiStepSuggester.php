<?php

namespace App\Services;

use App\Ai\StepSuggestionAgent;
use App\Models\AiCallCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiStepSuggester
{
    public function suggestSteps(
        User $user,
        string $title,
        string $description,
        array $currentSteps = [],
    ): array {
        // 1. Check daily ceiling (return [] without incrementing counter)
        $todayCount = $user->aiCallCounters()->where('day', today())->value('count') ?? 0;

        if ($todayCount >= config('ai.step_suggestion.ceiling_per_day')) {
            Log::warning('AI step suggestion ceiling reached', ['user_id' => $user->id]);

            return [];
        }

        // 2. Increment counter BEFORE dispatching (attempt-based; failures still count)
        DB::transaction(function () use ($user) {
            $counter = AiCallCounter::firstOrCreate(
                ['owner_id' => $user->id, 'day' => today()],
                ['count' => 0],
            );
            $counter->increment('count');
        });

        try {
            // 4. Dispatch agent
            $response = StepSuggestionAgent::make(
                user: $user,
                title: $title,
                description: $description,
                currentSteps: $currentSteps,
            )->prompt('Generate the step plan.');

            // 5. Validate response — parse JSON text then check shape
            $decoded = json_decode($response->text, true);
            $steps = $decoded['steps'] ?? null;
            $required = config('ai.step_suggestion.required_step_count');
            $maxLength = config('ai.step_suggestion.max_step_length');

            if (! is_array($steps) || count($steps) !== $required) {
                Log::warning('AI step suggestion returned unexpected step count', [
                    'count' => is_array($steps) ? count($steps) : null,
                    'required' => $required,
                ]);

                return [];
            }

            foreach ($steps as $step) {
                if (! is_string($step) || $step === '' || mb_strlen($step) > $maxLength) {
                    Log::warning('AI step suggestion returned invalid step value', ['step' => $step]);

                    return [];
                }
            }

            return $steps;
        } catch (\Throwable $e) {
            Log::warning('AI step suggestion failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
