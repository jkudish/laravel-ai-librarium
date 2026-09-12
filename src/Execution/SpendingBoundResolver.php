<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ResearchCompiler;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use LogicException;

final readonly class SpendingBoundResolver
{
    public function __construct(
        private ResearchCompiler $compiler,
        private Container $container,
    ) {}

    public function resolve(PendingResearch $research): SpendingBound
    {
        $selector = $research->selector();
        if ($selector['kind'] !== 'using' || $research->fallbackProfiles() !== []) {
            throw ValidationException::withMessages([
                'selector' => 'A spending bound requires using() with one exact Profile and no fallbacks.',
            ]);
        }

        $plan = $this->compiler->compileSpendingBoundRequest($research);
        if ($plan->preview->profiles->count() !== 1 || $plan->preview->fallbacks->isNotEmpty()) {
            throw new LogicException('The exact spending-bound request did not compile to one Profile.');
        }

        /** @var Profile $profile */
        $profile = $plan->preview->profiles->sole();
        if (! is_a($profile->driver, DefinesSpendingBound::class, true)) {
            return SpendingBound::unknown();
        }

        $driver = $this->container->make($profile->driver);
        if (! $driver instanceof Driver || ! $driver instanceof DefinesSpendingBound) {
            throw new LogicException("Research Driver [{$profile->driver}] is invalid.");
        }

        $prompt = $plan->preview->prompts->get($profile->id);
        if (! is_string($prompt)) {
            throw new LogicException("The rendered prompt for Profile [{$profile->id}] is missing.");
        }

        return $driver->spendingBound(new DriverRequest(
            requestId: $plan->requestId,
            requestedProfile: $profile,
            profile: $profile,
            prompt: $prompt,
            deadline: CarbonImmutable::now()->addSeconds($plan->timeout),
            progressCallback: static fn (): null => null,
        ));
    }
}
