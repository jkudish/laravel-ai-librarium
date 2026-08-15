<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Throwable;

final readonly class ResearchStatusStore
{
    public function __construct(
        private Repository $cache,
        private int $ttl,
    ) {}

    public function get(string $requestId): ?ResearchStatus
    {
        $value = $this->cache->get($this->terminalKey($requestId))
            ?? $this->cache->get($this->key($requestId));

        if (! is_array($value)
            || ! is_string($value['state'] ?? null)
            || ! is_string($value['updated_at'] ?? null)
            || (isset($value['progress']) && ! is_string($value['progress']))) {
            return null;
        }

        try {
            return ResearchStatus::fromArray([
                'state' => $value['state'],
                'progress' => $value['progress'] ?? null,
                'updated_at' => $value['updated_at'],
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function put(string $requestId, ResearchStatus $status): void
    {
        $this->cache->put($this->key($requestId), $status->toArray(), $this->ttl);
    }

    public function transitionToTerminal(string $requestId, ResearchStatus $status): bool
    {
        if (! in_array($status->state, [ResearchState::Completed, ResearchState::Failed], true)) {
            throw new InvalidArgumentException('Only completed or failed research may become terminal.');
        }

        return $this->cache->add($this->terminalKey($requestId), $status->toArray(), $this->ttl);
    }

    private function key(string $requestId): string
    {
        return 'librarium:status:'.$requestId;
    }

    private function terminalKey(string $requestId): string
    {
        return 'librarium:status:terminal:'.$requestId;
    }
}
