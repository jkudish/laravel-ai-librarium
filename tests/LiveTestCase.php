<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests;

abstract class LiveTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('LIBRARIUM_LIVE_TESTS') !== '1') {
            $this->markTestSkipped('Set LIBRARIUM_LIVE_TESTS=1 to opt in to live provider tests.');
        }
    }

    protected function preventsExternalRequests(): bool
    {
        return false;
    }
}
