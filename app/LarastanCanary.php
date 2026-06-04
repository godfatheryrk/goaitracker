<?php

namespace App;

/**
 * THROWAWAY — intentional Larastan failure to exercise the CI static-analysis
 * gate. This whole file (and its PR) gets deleted once the red Larastan check
 * is confirmed. Do NOT merge.
 */
class LarastanCanary
{
    public function brokenReturn(): int
    {
        return 'this is deliberately not an int';
    }
}
