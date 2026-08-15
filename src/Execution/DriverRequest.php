<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jkudish\LaravelAiLibrarium\Profile;

final readonly class DriverRequest
{
    /** @param Closure(string, string): void $progressCallback */
    public function __construct(
        public string $requestId,
        public Profile $requestedProfile,
        public Profile $profile,
        public string $prompt,
        public CarbonImmutable $deadline,
        private Closure $progressCallback,
    ) {}

    public function progress(string $message): void
    {
        $progress = Str::of($message)
            ->replaceMatches('/[\x00-\x1f\x7f]+/u', ' ')
            ->squish()
            ->limit(255, '')
            ->toString();

        if ($progress === '') {
            throw new InvalidArgumentException('Research progress must contain a human-readable message.');
        }

        ($this->progressCallback)($progress, $this->profile->id);
    }
}
