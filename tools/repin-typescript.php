#!/usr/bin/env php
<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Tools\TypeScriptRepin;

require __DIR__.'/TypeScriptRepin.php';

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments) || count($arguments) !== 3 || ! is_string($arguments[1] ?? null) || ! is_string($arguments[2] ?? null)) {
    fwrite(STDERR, "Usage: composer typescript:repin -- <path-to-librarium-checkout> <ref>\n");
    exit(2);
}

try {
    $pin = (new TypeScriptRepin)->repin($arguments[1], $arguments[2]);
    $totals = $pin->contractTotals;

    fwrite(STDOUT, implode("\n", [
        "Pinned jkudish/librarium {$pin->commit} (ref {$pin->ref}, tag ".($pin->tag ?? 'none').", package {$pin->packageVersion}).",
        "Contracts: {$totals['valid_fixtures']} valid and {$totals['invalid_fixtures']} invalid fixtures, {$totals['snapshot_files']} files, checksum index {$pin->contractChecksumsSha256}.",
        "Profiles: {$pin->profileCount} implemented, inventory {$pin->profilesSha256}.",
        'Review the diff, then run composer test:conformance.',
    ])."\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, $throwable->getMessage()."\n");
    exit(1);
}
