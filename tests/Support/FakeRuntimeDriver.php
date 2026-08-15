<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Closure;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use LogicException;
use Throwable;

final class FakeRuntimeDriver implements Driver
{
    public static int $constructorCalls = 0;

    /** @var list<DriverRequest> */
    public static array $requests = [];

    /** @var array<string, list<Closure|ResearchResult|Throwable>> */
    public static array $outcomes = [];

    public function __construct()
    {
        self::$constructorCalls++;
    }

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        self::$requests[] = $request;
        $outcomes = self::$outcomes[$request->profile->id] ?? [];
        $outcome = array_shift($outcomes);
        self::$outcomes[$request->profile->id] = $outcomes;

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        if ($outcome instanceof Closure) {
            $outcome = $outcome($request);
        }

        if (! $outcome instanceof ResearchResult) {
            throw new LogicException("No fake outcome was configured for [{$request->profile->id}].");
        }

        return $outcome;
    }

    public static function reset(): void
    {
        self::$constructorCalls = 0;
        self::$requests = [];
        self::$outcomes = [];
    }
}
