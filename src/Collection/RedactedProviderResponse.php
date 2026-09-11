<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final readonly class RedactedProviderResponse implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public string $provider,
        public string $profile,
        public Operation $operation,
        public int $status,
        public array $headers,
        public ?string $body,
        public ?string $omittedReason,
        public CarbonImmutable $receivedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'profile' => $this->profile,
            'operation' => $this->operation->value,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'omitted_reason' => $this->omittedReason,
            'received_at' => $this->receivedAt->toIso8601ZuluString(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
