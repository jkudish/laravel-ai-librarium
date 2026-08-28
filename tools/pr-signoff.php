#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\PrWorkflow;

require __DIR__.'/PrWorkflow.php';

$argv = $_SERVER['argv'] ?? [];
$arguments = is_array($argv) ? array_slice($argv, 1) : [];
$approvedSha = count($arguments) === 2
    && $arguments[0] === '--approved-sha'
    && is_string($arguments[1])
        ? $arguments[1]
        : null;

try {
    $result = (new PrWorkflow)->signoff($approvedSha);
    fwrite(STDOUT, "Signed off {$result['sha']}\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");
    exit(1);
}
