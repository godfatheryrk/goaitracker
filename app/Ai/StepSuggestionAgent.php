<?php

namespace App\Ai;

use App\Models\User;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Timeout(15)]
#[Temperature(0.7)]
class StepSuggestionAgent implements Agent
{
    use Promptable;

    public function __construct(
        private User $user,
        private string $title,
        private string $description,
        private array $currentSteps = [],
    ) {}

    public function provider(): string
    {
        return config('ai.default', 'groq');
    }

    public function model(): string
    {
        return config('ai.step_suggestion.model', 'llama-3.3-70b-versatile');
    }

    public function instructions(): string
    {
        $base = 'You are an assistant that proposes the first short, actionable step plan for a personal venture/plan/event/activity. '
            .'Always produce exactly 7 short steps (max 200 characters each) in logical order. '
            .'Each step must be a complete imperative sentence. '
            .'Do not include rationale, expected duration, or any other field. '
            .'Respond with ONLY a valid JSON object with a single key "steps" containing an array of exactly 7 strings. '
            .'No markdown, no code fences, no explanation — just the JSON object.';

        if (! empty($this->currentSteps)) {
            $list = implode("\n", array_map(
                fn ($s, $i) => ($i + 1).'. '.$s,
                $this->currentSteps,
                array_keys($this->currentSteps),
            ));
            $base .= ' If existing steps are provided, do not duplicate or paraphrase them; '
                ."produce 7 NEW steps that complement them.\n\nExisting steps:\n{$list}";
        }

        return $base;
    }
}
