<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;

it('preserves the exact research input', function (): void {
    $research = Librarium::query("  Research this exactly.\n");

    expect($research)
        ->input->toBe("  Research this exactly.\n")
        ->requestId->not->toBeEmpty();
});
