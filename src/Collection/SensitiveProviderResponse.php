<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use LogicException;
use WeakMap;

final class SensitiveProviderResponse
{
    /** @var WeakMap<object, array{headers: array<string, list<string>>, body: string}>|null */
    private static ?WeakMap $payloads = null;

    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        private readonly string $provider,
        private readonly string $profile,
        private readonly Operation $operation,
        private readonly int $status,
        array $headers,
        string $body,
        private readonly CarbonImmutable $receivedAt,
    ) {
        self::$payloads ??= new WeakMap;
        self::$payloads[$this] = ['headers' => $headers, 'body' => $body];
    }

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
        return $this->payload()['headers'];
    }

    public function body(): string
    {
        return $this->payload()['body'];
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

    public function __clone(): void
    {
        throw new LogicException('Sensitive provider responses cannot be cloned.');
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

    /** @return array{headers: array<string, list<string>>, body: string} */
    private function payload(): array
    {
        $payloads = self::$payloads;
        if ($payloads === null || ! isset($payloads[$this])) {
            throw new LogicException('The sensitive provider response payload is unavailable.');
        }

        return $payloads[$this];
    }
}
