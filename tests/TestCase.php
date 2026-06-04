<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render layouts that use @vite; without a build the
        // manifest lookup throws. Tests assert on HTML, not on real assets,
        // so short-circuit Vite here — keeps the PHPUnit suite build-independent
        // (real asset rendering is covered by the E2E workflow).
        $this->withoutVite();
    }
}
