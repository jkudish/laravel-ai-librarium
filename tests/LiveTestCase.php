<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests;

abstract class LiveTestCase extends TestCase
{
    protected function preventsExternalRequests(): bool
    {
        return false;
    }
}
