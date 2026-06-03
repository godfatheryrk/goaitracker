<?php

namespace App\Services;

use App\Models\User;

/**
 * Deterministic, no-network stand-in for {@see AiStepSuggester}, used ONLY when the
 * E2E flag is set (see AppServiceProvider). It keeps the running server reproducible
 * and free of any Groq HTTP call so each risk path is exercised on demand.
 *
 * Behaviour is keyed on the venture title:
 *  - a title containing the case-insensitive sentinel `force-ai-empty` returns `[]`,
 *    driving the graceful-degrade path (test-plan.md risk #1);
 *  - any other title returns a fixed list of 7 distinct steps (risk #2).
 *
 * It does NOT call parent::, makes no HTTP request, and never touches AiCallCounter.
 */
class FakeAiStepSuggester extends AiStepSuggester
{
    private const SENTINEL = 'force-ai-empty';

    public function suggestSteps(
        User $user,
        string $title,
        string $description,
        array $currentSteps = [],
    ): array {
        if (str_contains(strtolower($title), self::SENTINEL)) {
            return [];
        }

        return [
            '[E2E] Define the goal and success criteria',
            '[E2E] Break the work into milestones',
            '[E2E] Draft a rough timeline',
            '[E2E] Estimate the budget',
            '[E2E] Gather the tools and materials',
            '[E2E] Schedule the first work session',
            '[E2E] Review progress and adjust the plan',
        ];
    }
}
