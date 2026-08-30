#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\PrWorkflow;

require __DIR__.'/PrWorkflow.php';

try {
    $result = (new PrWorkflow)->check();
    $sha = $result['receipt']['sha'] ?? null;
    $planId = $result['receipt']['planId'] ?? null;

    if (! is_string($sha) || ! is_string($planId)) {
        throw new RuntimeException('Verification returned malformed receipt details.');
    }

    fwrite(STDOUT, "Verified {$sha}\nPlan: {$planId}\nReceipt: {$result['path']}\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");
    exit(1);
}
