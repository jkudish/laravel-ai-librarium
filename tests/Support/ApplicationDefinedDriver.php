<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

final class ApplicationDefinedDriver implements Driver
{
    public static ?Container $container = null;

    public static ?DriverRequest $request = null;

    public static int $runs = 0;

    public function __construct(Container $container)
    {
        self::$container = $container;
    }

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:offline'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        self::$request = $request;
        self::$runs++;
        $request->progress('Application-defined Driver completed.');

        return ResearchResult::fromArray([
            'id' => 'application-result',
            'content_format' => 'text',
            'content' => 'Result supplied by the consuming application.',
            'requested_profile' => $request->requestedProfile->id,
            'provider' => $request->profile->provider,
            'profile' => $request->profile->id,
            'provenance' => [
                'result_kind' => 'search_results',
                'retrieval_methods' => ['search_endpoint'],
                'corpora' => ['web'],
                'observed_at' => CarbonImmutable::now()->toIso8601ZuluString(),
                'observation_mode' => 'api_output',
            ],
            'citations' => [],
            'completed_at' => CarbonImmutable::now()->toIso8601ZuluString(),
        ]);
    }
}
