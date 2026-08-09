<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

final readonly class CollectionProvenance
{
    /**
     * @param  array<string, mixed>|null  $surfaceContext
     * @param  array<string, string>|null  $correlationKeys
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public ProviderIdentity $provider,
        public string $accessMode,
        public string $operatorId,
        public ?string $collectorId = null,
        public ?string $surfaceId = null,
        public ?array $surfaceContext = null,
        public ?string $originKey = null,
        public ?array $correlationKeys = null,
        public ?array $extensions = null,
    ) {}
}
