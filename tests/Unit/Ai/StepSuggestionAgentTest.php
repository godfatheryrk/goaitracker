<?php

namespace Tests\Unit\Ai;

use App\Ai\StepSuggestionAgent;
use PHPUnit\Framework\TestCase;

class StepSuggestionAgentTest extends TestCase
{
    public function test_instructions_carry_the_venture_title_and_description_to_the_model(): void
    {
        // Regression guard: title + description MUST reach the model, otherwise
        // the AI plans a generic venture (PRD core value). The bug that prompted
        // this test had both fields stored on the agent but never read.
        $instructions = (string) (new StepSuggestionAgent(
            title: 'Learn welding',
            description: 'MIG and TIG basics for a beginner',
        ))->instructions();

        $this->assertStringContainsString('Learn welding', $instructions);
        $this->assertStringContainsString('MIG and TIG basics for a beginner', $instructions);
    }

    public function test_instructions_omit_description_line_when_blank(): void
    {
        $instructions = (string) (new StepSuggestionAgent(
            title: 'Throw a party',
            description: '',
        ))->instructions();

        $this->assertStringContainsString('Throw a party', $instructions);
        $this->assertStringNotContainsString('Venture description:', $instructions);
    }

    public function test_instructions_still_carry_existing_steps_for_the_extension_flow(): void
    {
        $instructions = (string) (new StepSuggestionAgent(
            title: 'Renovate the bathroom',
            description: 'Tiles, plumbing, paint',
            currentSteps: ['Measure the room', 'Buy tiles'],
        ))->instructions();

        $this->assertStringContainsString('Measure the room', $instructions);
        $this->assertStringContainsString('Buy tiles', $instructions);
    }
}
