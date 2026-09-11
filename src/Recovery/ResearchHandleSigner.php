<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Recovery;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use JsonException;
use LogicException;

/** @internal */
final readonly class ResearchHandleSigner
{
    public function __construct(private ConfigRepository $config) {}

    public function assertIssuable(Profile $profile, string $requestId): void
    {
        $this->issue(
            profile: $profile,
            requestId: $requestId,
            reference: 'preflight',
            submittedAt: CarbonImmutable::now(),
        );
    }

    public function issue(
        Profile $profile,
        string $requestId,
        string $reference,
        CarbonImmutable $submittedAt,
        ?Usage $submittedUsage = null,
    ): ResearchHandle {
        $fields = [
            'schema_version' => ResearchHandle::SCHEMA_VERSION,
            'request_id' => $requestId,
            'profile' => $profile->id,
            'provider' => $profile->provider,
            'model' => $profile->model,
            'reference' => $reference,
            'submitted_at' => $submittedAt->utc()->toIso8601ZuluString(),
            'submitted_usage' => $submittedUsage?->toArray(),
        ];

        return new ResearchHandle(
            requestId: $requestId,
            profile: $profile->id,
            provider: $profile->provider,
            model: $profile->model,
            reference: $reference,
            submittedAt: $submittedAt,
            submittedUsage: $submittedUsage,
            fingerprint: $this->fingerprint($fields, $profile),
        );
    }

    public function verify(ResearchHandle $handle, Profile $profile): void
    {
        $fields = $handle->toArray();
        unset($fields['fingerprint']);

        if ($handle->profile !== $profile->id
            || $handle->provider !== $profile->provider
            || $handle->model !== $profile->model
            || ! hash_equals($this->fingerprint($fields, $profile), $handle->fingerprint)) {
            throw ValidationException::withMessages([
                'handle' => 'The research handle does not match the current Profile binding.',
            ]);
        }
    }

    /** @param array<string, mixed> $fields */
    private function fingerprint(array $fields, Profile $profile): string
    {
        try {
            $payload = json_encode($this->canonical([
                'handle' => $fields,
                'profile' => [
                    'id' => $profile->id,
                    'driver' => $profile->driver,
                    'provider' => $profile->provider,
                    'model' => $profile->model,
                    'result_kind' => $profile->resultKind->value,
                    'grounding' => $profile->grounding->value,
                    'observation' => $profile->observation->value,
                    'corpora' => $profile->corpora->map(fn (Corpus $corpus): string => $corpus->value)->values()->all(),
                    'retrieval_methods' => $profile->retrievalMethods->map(fn (RetrievalMethod $method): string => $method->value)->values()->all(),
                    'options' => $profile->options,
                ],
                'credential' => $profile->credential,
            ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new LogicException('The durable Profile binding cannot be encoded.', previous: $exception);
        }

        return hash_hmac('sha256', $payload, $this->key());
    }

    private function key(): string
    {
        $configured = $this->config->get('app.key');
        if (! is_string($configured) || trim($configured) === '') {
            throw new LogicException('A configured application key is required for durable research handles.');
        }

        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if (! is_string($decoded) || $decoded === '') {
                throw new LogicException('The configured application key is invalid.');
            }

            return $decoded;
        }

        return $configured;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
    }
}
