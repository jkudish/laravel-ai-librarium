<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\SourceKind;

final readonly class NormalizedSource
{
    /**
     * @param  list<string>  $citationIds
     * @param  array<string, string>|null  $correlationKeys
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public string $sourceId,
        public SourceKind $sourceKind,
        public array $citationIds,
        public ?string $canonicalUrl = null,
        public ?string $providerReference = null,
        public ?string $sourceCategory = null,
        public ?string $datasetId = null,
        public ?string $publisherId = null,
        public ?string $publisherName = null,
        public ?array $correlationKeys = null,
        public ?array $extensions = null,
    ) {}
}
