<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Facades;

use Illuminate\Support\Facades\Facade;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium as LibrariumContract;
use Jkudish\LaravelAiLibrarium\PendingResearch;

/**
 * @method static PendingResearch query(string $input)
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
