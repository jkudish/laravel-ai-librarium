<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\PendingResearch;

it('preserves the exact research input', function (): void {
    $research = new PendingResearch("  Research this exactly.\n");

    expect($research->input)->toBe("  Research this exactly.\n");
});
