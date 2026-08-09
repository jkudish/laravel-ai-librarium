<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

final readonly class ProviderIdentity
{
    public function __construct(
        public string $providerId,
        public string $profileId,
        public ?string $modelId = null,
    ) {}
}
