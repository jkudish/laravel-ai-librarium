<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use LogicException;

final class SensitiveProviderResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $profile,
        private readonly Operation $operation,
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
        private readonly CarbonImmutable $receivedAt,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function receivedAt(): CarbonImmutable
    {
        return $this->receivedAt;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Sensitive provider responses cannot be serialized.');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'provider' => $this->provider,
            'profile' => $this->profile,
            'operation' => $this->operation->value,
            'status' => $this->status,
            'headers' => '[SENSITIVE]',
            'body' => '[SENSITIVE]',
            'received_at' => $this->receivedAt->toIso8601ZuluString(),
        ];
    }
}
