<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Webhooks;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\ResearchState;
use LogicException;
use Throwable;

final readonly class WebhookSignalStore
{
    public function __construct(
        private Repository $cache,
        private ValidationFactory $validator,
        private int $ttl,
    ) {}

    public function bind(string $provider, string $requestId, string $profile, string $providerReference): void
    {
        $this->validateIdentity($provider, $requestId, $profile, $providerReference);

        $binding = [
            'provider' => $provider,
            'request_id' => $requestId,
            'profile' => $profile,
            'provider_reference' => $providerReference,
        ];
        $key = $this->bindingKey($provider, $providerReference);

        if ($this->cache->add($key, $binding, $this->ttl)) {
            return;
        }

        if ($this->cache->get($key) !== $binding) {
            throw new LogicException('The provider reference is already bound to another research attempt.');
        }
    }

    public function record(string $provider, string $providerReference, string $deliveryId, ResearchState $state): bool
    {
        $this->validator->make(
            [
                'provider' => $provider,
                'provider_reference' => $providerReference,
                'delivery_id' => $deliveryId,
                'state' => $state->value,
            ],
            [
                'provider' => ['required', 'string', 'max:255'],
                'provider_reference' => ['required', 'string', 'max:1024'],
                'delivery_id' => ['required', 'string', 'max:1024'],
                'state' => ['required', Rule::in([ResearchState::Completed->value, ResearchState::Failed->value])],
            ],
        )->validate();

        $binding = $this->cache->get($this->bindingKey($provider, $providerReference));
        if (! is_array($binding)
            || ($binding['provider'] ?? null) !== $provider
            || ! is_string($binding['request_id'] ?? null)
            || ! is_string($binding['profile'] ?? null)
            || ($binding['provider_reference'] ?? null) !== $providerReference) {
            throw ValidationException::withMessages([
                'provider_reference' => 'The provider reference is not bound to an active research attempt.',
            ]);
        }

        if (! $this->cache->add(
            $this->signalKey($binding['request_id'], $binding['profile']),
            [
                'provider' => $provider,
                'request_id' => $binding['request_id'],
                'profile' => $binding['profile'],
                'provider_reference' => $providerReference,
                'state' => $state->value,
                'received_at' => CarbonImmutable::now()->utc()->toIso8601ZuluString(),
            ],
            $this->ttl,
        )) {
            return false;
        }

        $this->cache->add($this->deliveryKey($provider, $providerReference, $deliveryId), true, $this->ttl);

        return true;
    }

    public function get(string $requestId, string $profile): ?ResearchWebhookSignal
    {
        $value = $this->cache->get($this->signalKey($requestId, $profile));

        if (! is_array($value)
            || ($value['request_id'] ?? null) !== $requestId
            || ($value['profile'] ?? null) !== $profile
            || ! is_string($value['provider'] ?? null)
            || ! is_string($value['provider_reference'] ?? null)
            || ! is_string($value['state'] ?? null)
            || ! is_string($value['received_at'] ?? null)) {
            return null;
        }

        try {
            $state = ResearchState::from($value['state']);
            if (! in_array($state, [ResearchState::Completed, ResearchState::Failed], true)) {
                return null;
            }

            return new ResearchWebhookSignal(
                requestId: $requestId,
                profile: $profile,
                provider: $value['provider'],
                providerReference: $value['provider_reference'],
                state: $state,
                receivedAt: CarbonImmutable::parse($value['received_at']),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function validateIdentity(string $provider, string $requestId, string $profile, string $providerReference): void
    {
        $this->validator->make(
            compact('provider', 'requestId', 'profile', 'providerReference'),
            [
                'provider' => ['required', 'string', 'max:255'],
                'requestId' => ['required', 'string', 'max:255'],
                'profile' => ['required', 'string', 'max:255'],
                'providerReference' => ['required', 'string', 'max:1024'],
            ],
        )->validate();
    }

    private function bindingKey(string $provider, string $providerReference): string
    {
        return 'librarium:webhook:binding:'.hash('sha256', $provider."\0".$providerReference);
    }

    private function deliveryKey(string $provider, string $providerReference, string $deliveryId): string
    {
        return 'librarium:webhook:delivery:'.hash('sha256', $provider."\0".$providerReference."\0".$deliveryId);
    }

    private function signalKey(string $requestId, string $profile): string
    {
        return 'librarium:webhook:signal:'.hash('sha256', $requestId."\0".$profile);
    }
}
