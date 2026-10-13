<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Facades;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\Testing\LibrariumFake;
use RuntimeException;

/**
 * @method static PendingResearch research(string $question)
 * @method static \Jkudish\LaravelAiLibrarium\Run|null find(string $id)
 * @method static array<string, mixed> sources()
 * @method static void extendStore(string $name, \Closure $factory)
 * @method static void extendCollector(string $name, \Closure $factory)
 *
 * @see LibrariumContract
 */
final class Librarium extends Facade
{
    /** @param list<Run> $responses */
    public static function fake(array $responses = []): LibrariumFake
    {
        $app = self::getFacadeApplication();
        if (! $app instanceof Application) {
            throw new RuntimeException('Librarium cannot be faked before the Laravel application is available.');
        }

        $fake = new LibrariumFake($app->make(LibrariumManager::class), $responses);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LibrariumContract::class;
    }
}
