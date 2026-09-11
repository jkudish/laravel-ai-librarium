<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Facades;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Jkudish\LaravelAiLibrarium\Contracts\CollectsResearch;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;
use Jkudish\LaravelAiLibrarium\DurableOutcome;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\ResearchStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Testing\LibrariumFake;
use RuntimeException;

/**
 * @method static PendingResearch query(string $input)
 * @method static DurableOutcome retrieve(ResearchHandle $handle, bool $capture = true)
 * @method static ResearchStatus|null status(string $requestId)
 *
 * @see LibrariumContract
 */
final class Librarium extends Facade
{
    /** @param list<ResearchResponse> $responses */
    public static function fake(array $responses = []): LibrariumFake
    {
        $fake = new LibrariumFake($responses);
        $app = self::getFacadeApplication();
        if (! $app instanceof Application) {
            throw new RuntimeException('Librarium cannot be faked before the Laravel application is available.');
        }

        $app->instance(ExecutesResearch::class, $fake);
        $app->instance(CollectsResearch::class, $fake);
        $app->instance(RecoversResearch::class, $fake);
        $app->forgetInstance(LibrariumManager::class);
        $app->forgetInstance(LibrariumContract::class);

        self::clearResolvedInstance();
        self::swap($app->make(LibrariumContract::class));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LibrariumContract::class;
    }
}
