<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Values;

use Jkudish\LaravelAiLibrarium\Interchange\Enums\DurableHandleStatus;

final readonly class DurableHandle
{
    /**
     * @param  array<string, mixed>|null  $extensions
     */
    public function __construct(
        public string $handleId,
        public string $providerTaskId,
        public ProviderIdentity $provider,
        public string $submittedAt,
        public DurableHandleStatus $status,
        public ?string $lastObservedAt = null,
        public ?array $extensions = null,
    ) {}
}
