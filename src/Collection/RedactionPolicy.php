<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

/** @internal */
final readonly class RedactionPolicy
{
    /**
     * @param  list<string>  $secrets
     * @param  list<string>  $paths
     * @param  list<string>  $patterns
     */
    public function __construct(
        public array $secrets,
        public array $paths,
        public array $patterns,
        public string $replacement,
    ) {}
}
