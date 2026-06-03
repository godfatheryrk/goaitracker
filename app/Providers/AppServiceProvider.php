<?php

namespace App\Providers;

use App\Services\AiStepSuggester;
use App\Services\FakeAiStepSuggester;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Under the E2E flag, swap the suggester for a deterministic, no-network fake.
        // Read via env() (not config()) because config isn't built during register();
        // the E2E server never runs config:cache, so env() is reliable here.
        if (env('E2E_FAKE_AI')) { // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig (intentional: config isn't built during register() and the E2E server never runs config:cache)
            $this->app->singleton(AiStepSuggester::class, fn () => new FakeAiStepSuggester);

            return;
        }

        $this->app->singleton(AiStepSuggester::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(UrlGenerator $url): void
    {
        if ($this->app->environment('production')) {
            $url->forceScheme('https');
        }
    }
}
