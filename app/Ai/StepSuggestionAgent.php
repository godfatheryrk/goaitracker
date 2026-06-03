<?php

namespace App\Ai;

use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Temperature(0.7)]
class StepSuggestionAgent implements Agent
{
    use Promptable;

    public function __construct(
        private string $title,
        private string $description,
        private array $currentSteps = [],
    ) {}

    public function timeout(): int
    {
        return config('ai.step_suggestion.timeout_seconds', 15);
    }

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

        // The venture the user is planning. Without this the model has no idea
        // WHAT it is planning and returns a generic plan — the product's whole
        // value (PRD: "AI proposes the step list from the venture description")
        // depends on these two fields reaching the model.
        $base .= "\n\nVenture title: {$this->title}";

        if (trim($this->description) !== '') {
            $base .= "\nVenture description: {$this->description}";
        }

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
