<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiLibrarium\Tests\LiveTestCase;
use Jkudish\LaravelAiLibrarium\Tests\TestCase;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;

uses(TestCase::class)->in('Feature', 'Unit');
uses(LiveTestCase::class)->in('Live');

/** Build a validated private variant for provider-contract tests. */
function variantRequest(string $id, string $question, int $timeout = 6900): DriverRequest
{
    $catalog = app(ProfileCatalog::class);
    $availability = $catalog->find($id) ?? throw new LogicException('Unknown test variant.');
    if ($availability->reasons !== []) {
        throw ValidationException::withMessages(['variant' => $availability->reasons]);
    }
    $profile = $availability->profile;

    return new DriverRequest(
        'test-variant-request', $profile, $profile, $profile->renderPrompt($question),
        CarbonImmutable::now()->addSeconds($timeout), static function (): void {},
    );
}

function variantBound(string $id, string $question): SpendingBound
{
    $request = variantRequest($id, $question);
    $driver = app($request->profile->driver);

    return $driver instanceof DefinesSpendingBound
        ? $driver->spendingBound($request)
        : SpendingBound::unknown();
}

function variantRun(string $id, string $question, int $timeout = 6900): ResearchResult
{
    $request = variantRequest($id, $question, $timeout);

    return app($request->profile->driver)->run($request);
}

function variantFailure(string $id, string $question, int $timeout = 6900): DriverException
{
    try {
        variantRun($id, $question, $timeout);
    } catch (DriverException $exception) {
        return $exception;
    }

    throw new LogicException('Expected the driver operation to fail.');
}

function variantQuote(string $id): CostQuote
{
    $profile = variantRequest($id, 'Pricing')->profile;

    return app(CostResolver::class)->resolve(new PricingObservation($profile->pricingIdentity, $profile->pricingUsage));
}

/** Exercise stored diagnostics through the new public Run contract. */
function variantResearch(string $id, string $question, int $timeout = 6900): PendingResearch
{
    $request = variantRequest($id, $question, $timeout);
    $binding = config('librarium.profiles.'.$id);
    config(['librarium.runs.store' => 'memory', 'librarium.timeout' => $timeout,
        'librarium.sources.probe' => ['key' => $request->profile->credential, 'quick' => $binding],
    ]);

    return Librarium::research($question)->only(['probe']);
}
