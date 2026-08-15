<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Webhooks;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\ResearchState;

final readonly class ResearchWebhookSignal
{
    public function __construct(
        public string $requestId,
        public string $profile,
        public string $provider,
        public string $providerReference,
        public ResearchState $state,
        public CarbonImmutable $receivedAt,
    ) {}
}
