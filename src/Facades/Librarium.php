<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Facades;

use Illuminate\Support\Facades\Facade;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\ResearchStatus;

/**
 * @method static PendingResearch query(string $input)
 * @method static ResearchStatus|null status(string $requestId)
 *
 * @see LibrariumContract
 */
final class Librarium extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LibrariumContract::class;
    }
}
