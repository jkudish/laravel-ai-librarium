<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Recovery;

use Jkudish\LaravelAiLibrarium\Responses\Usage;

/** @internal */
final readonly class DurableSubmission
{
    public function __construct(
        public string $reference,
        public ?Usage $usage = null,
    ) {}
}
