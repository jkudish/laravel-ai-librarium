<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Interchange\Enums\SourceKind;

final readonly class Citation
{
    /** @param array<string, mixed>|null $extensions */
    public function __construct(
        public string $citationId,
        public SourceKind $sourceKind,
        public CitationDerivation $derivation,
        public CollectionProvenance $provenance,
        public ?string $sourceCategory = null,
        public ?string $datasetId = null,
        public ?string $url = null,
        public ?string $title = null,
        public ?string $snippet = null,
        public ?string $providerReference = null,
        public ?string $location = null,
        public ?string $retrievedAt = null,
        public ?array $extensions = null,
    ) {}
}
