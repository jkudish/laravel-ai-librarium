<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Enums;

enum CitationDerivation: string
{
    case ProviderReported = 'provider_reported';
    case CollectorExtracted = 'collector_extracted';
    case LibrariumInferred = 'librarium_inferred';
}
