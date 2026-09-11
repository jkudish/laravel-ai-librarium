#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\ReleaseReadiness;

require __DIR__.'/ReleaseReadiness.php';

try {
    (new ReleaseReadiness)->check();
    fwrite(STDOUT, "Release readiness checks passed.\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");
    exit(1);
}
