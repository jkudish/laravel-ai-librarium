<?php

declare(strict_types=1);

/*
 * Local fail-closed regressions for the brave-answers spending bound. They
 * prove Librarium's behavior only: the classification stays `unknown`,
 * unproven controls are rejected loudly instead of silently serialized, and
 * the exact paid payload carries no cap. They do not prove the absence of
 * provider capabilities; see docs/brave-answers-spending-bounds.md for the
 * provider-contract evidence and the guarantees a bound would require.
 */

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Drivers\Brave\BraveAnswersDriver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Facades\Librarium;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\ResearchCompiler;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\SpendingBoundKind;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\ValueObjects\CostQuote;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use LogicException;

beforeEach(function (): void {
    config()->set('librarium.profiles.brave-answers.enabled', true);
    config()->set('librarium.profiles.brave-answers.credential', 'testing-bound-key');
});

it('keeps the shipped grounded answer unclassified as any spending bound', function (): void {
    $bound = Librarium::query('An exact grounded question.')->using('brave-answers')->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($bound->toArray())->toBe(['kind' => 'unknown'])
        ->and($bound->maximumCost)->toBeNull()
        ->and($bound->pricingIdentity)->toBeNull()
        ->and($bound->quantities)->toBeNull();
    Http::assertNothingSent();
});

it('does not promote the configured pricing preview estimate or a maxCost comparison into a bound', function (): void {
    $resolver = new class implements CostResolver
    {
        public int $calls = 0;

        public function resolve(PricingObservation $observation): CostQuote
        {
            $this->calls++;

            throw new LogicException('Spending-bound compilation must not resolve rates.');
        }
    };
    app()->when(ResearchCompiler::class)
        ->needs(CostResolver::class)
        ->give(fn (): CostResolver => $resolver);

    $bound = Librarium::query('Do not estimate this grounded answer.')
        ->using('brave-answers')
        ->maxCost('0.000001')
        ->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown)
        ->and($resolver->calls)->toBe(0);
    Http::assertNothingSent();
});

it('stays unknown for every provider-documented Answers model', function (string $model): void {
    config()->set('librarium.profiles.brave-answers.model', $model);

    $bound = Librarium::query('Any grounded question.')->using('brave-answers')->spendingBound();

    expect($bound->kind)->toBe(SpendingBoundKind::Unknown);
    Http::assertNothingSent();
})->with([
    'shipped single-search model' => 'brave',
    'provider default model' => 'brave-pro',
]);

it('rejects every provider-documented spending control instead of silently accepting it on this Profile', function (array $options): void {
    config()->set('librarium.profiles.brave-answers.options', $options);

    expect(fn () => Librarium::query('An exact grounded question.')->using('brave-answers')->spendingBound())
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    'output token cap' => fn () => ['max_completion_tokens' => 512],
    'qualitative context size' => fn () => ['web_search_options' => ['search_context_size' => 'low']],
    'research mode' => fn () => ['enable_research' => true],
    'research per-query token ceiling' => fn () => ['research_maximum_number_of_tokens_per_query' => 1024],
    'research query ceiling' => fn () => ['research_maximum_number_of_queries' => 1],
    'research iteration ceiling' => fn () => ['research_maximum_number_of_iterations' => 1],
    'research time budget' => fn () => ['research_maximum_number_of_seconds' => 1],
    'research per-query result ceiling' => fn () => ['research_maximum_number_of_results_per_query' => 1],
]);

it('serializes no spending control into the exact paid grounded request', function (): void {
    Http::fake(function (Request $request) {
        expect($request->data())->toBe([
            'model' => 'brave',
            'messages' => [['role' => 'user', 'content' => 'What changed?']],
            'stream' => true,
            'enable_citations' => true,
        ]);

        return Http::response('data: '.json_encode([
            'choices' => [['delta' => ['content' => 'Answer. <citation>{"url":"https://example.com/source","title":"Source"}</citation>']]],
        ], JSON_THROW_ON_ERROR)."\n\ndata: [DONE]\n\n");
    });

    $result = app(BraveAnswersDriver::class)->run(braveBoundRequest());

    expect($result->content)->toBe('Answer.');
    Http::assertSentCount(1);
});

function braveBoundRequest(): DriverRequest
{
    /** @var Collection<int, Corpus> $corpora */
    $corpora = collect([Corpus::Web]);
    /** @var Collection<int, RetrievalMethod> $retrievalMethods */
    $retrievalMethods = collect([RetrievalMethod::SearchEndpoint]);

    $profile = new Profile(
        id: 'brave-answers',
        driver: BraveAnswersDriver::class,
        provider: 'brave',
        model: 'brave',
        resultKind: ResultKind::GroundedAnswer,
        grounding: GroundingPolicy::Required,
        observation: ObservationMode::ApiOutput,
        corpora: $corpora,
        retrievalMethods: $retrievalMethods,
        prompt: '{{ query }}',
        enabled: true,
        options: [],
        credential: 'testing-brave-secret',
    );

    return new DriverRequest(
        requestId: 'request-brave-bound-1',
        requestedProfile: $profile,
        profile: $profile,
        prompt: 'What changed?',
        deadline: CarbonImmutable::now()->addSeconds(30),
        progressCallback: static fn (): null => null,
    );
}
