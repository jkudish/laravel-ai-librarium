<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Profile;
use Throwable;

final readonly class ResponseRedactor
{
    /** @var list<string> */
    private const array SAFE_HEADERS = [
        'content-type',
        'date',
        'retry-after',
        'request-id',
        'x-request-id',
        'openai-request-id',
        'cf-ray',
    ];

    public function __construct(
        private ConfigRepository $config,
        private ValidationFactory $validator,
    ) {}

    /** @param Collection<int, Profile> $profiles */
    public function prepareProfiles(Collection $profiles): RedactionPolicy
    {
        $configured = $this->config->get('librarium.redaction', []);
        $configured = is_array($configured) ? $configured : ['__invalid' => true];

        $validator = $this->validator->make(['redaction' => $configured], [
            'redaction' => ['array:paths,patterns,replacement'],
            'redaction.paths' => ['sometimes', 'array', 'list'],
            'redaction.paths.*' => ['string', 'min:1', 'max:512', 'regex:/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D'],
            'redaction.patterns' => ['sometimes', 'array', 'list'],
            'redaction.patterns.*' => [
                'string',
                'min:3',
                'max:1024',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || self::invalidPattern($value)) {
                        $fail("The {$attribute} field must be a valid regular expression.");
                    }
                },
            ],
            'redaction.replacement' => ['sometimes', 'string', 'min:1', 'max:64', 'regex:/^(?!\s)(?!.*\s$)[^\x00-\x1f\x7f]+$/u'],
        ]);
        $validator->validate();

        $pathValues = data_get($configured, 'paths', []);
        /** @var list<string> $paths */
        $paths = is_array($pathValues) ? array_values(array_filter($pathValues, is_string(...))) : [];
        $patternValues = data_get($configured, 'patterns', []);
        /** @var list<string> $patterns */
        $patterns = is_array($patternValues) ? array_values(array_filter($patternValues, is_string(...))) : [];
        $replacement = data_get($configured, 'replacement', '[REDACTED]');

        return new RedactionPolicy(
            secrets: $this->secrets($profiles),
            paths: $paths,
            patterns: $patterns,
            replacement: is_string($replacement) ? $replacement : '[REDACTED]',
        );
    }

    public function redact(SensitiveCollection $sensitive, RedactionPolicy $policy): CollectedResponse
    {
        return new CollectedResponse(
            response: $sensitive->response(),
            coverage: $sensitive->coverage(),
            evidence: $this->redactResponses($sensitive->responses(), $policy),
            diagnostics: $sensitive->diagnostics(),
            startedAt: $sensitive->startedAt(),
        );
    }

    /**
     * @param  list<SensitiveProviderResponse>  $responses
     * @return Collection<int, RedactedProviderResponse>
     */
    public function redactResponses(array $responses, RedactionPolicy $policy): Collection
    {
        return collect($responses)
            ->map(fn (SensitiveProviderResponse $response): RedactedProviderResponse => $this->redactResponse($response, $policy));
    }

    private function redactResponse(SensitiveProviderResponse $response, RedactionPolicy $policy): RedactedProviderResponse
    {
        [$body, $reason] = $this->body($response, $policy);
        try {
            $headers = $this->headers($response->headers(), $policy);
        } catch (Throwable) {
            $headers = [];
            $body = null;
            $reason = 'redaction_failed';
        }

        return new RedactedProviderResponse(
            provider: $response->provider(),
            profile: $response->profile(),
            operation: $response->operation(),
            status: $response->status(),
            headers: $headers,
            body: $body,
            omittedReason: $reason,
            receivedAt: $response->receivedAt(),
        );
    }

    /** @return array{string|null, string|null} */
    private function body(SensitiveProviderResponse $response, RedactionPolicy $policy): array
    {
        $body = $response->body();
        if (! mb_check_encoding($body, 'UTF-8')) {
            return [null, 'invalid_utf8'];
        }

        $contentType = $this->firstHeader($response->headers(), 'content-type');
        $trimmed = ltrim($body);
        $structured = str_contains(strtolower($contentType ?? ''), 'json')
            || str_starts_with($trimmed, '{')
            || str_starts_with($trimmed, '[');

        if ($structured) {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (Throwable) {
                return [null, 'invalid_json'];
            }

            try {
                $redacted = $this->redactValue($decoded, $policy);

                return [json_encode(
                    $redacted,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                ), null];
            } catch (Throwable) {
                return [null, 'redaction_failed'];
            }
        }

        try {
            return [$this->redactString($body, $policy), null];
        } catch (Throwable) {
            return [null, 'redaction_failed'];
        }
    }

    private function redactValue(mixed $value, RedactionPolicy $policy, string $path = ''): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value, $policy);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $segment = is_string($key) ? $key : (string) $key;
            $itemPath = $path === '' ? $segment : $path.'.'.$segment;
            if ((is_string($key) && $this->sensitiveKey($key)) || in_array($itemPath, $policy->paths, true)) {
                $value[$key] = $policy->replacement;

                continue;
            }

            $value[$key] = $this->redactValue($item, $policy, $itemPath);
        }

        return $value;
    }

    private function redactString(string $value, RedactionPolicy $policy): string
    {
        $redacted = str_replace($policy->secrets, $policy->replacement, $value);
        foreach ($policy->secrets as $secret) {
            if (! str_contains($secret, '%')) {
                continue;
            }
            // Percent-escape hex case is insignificant; literal credential bytes remain case-sensitive.
            $pattern = preg_replace_callback('/%[0-9a-f]{2}/i', static fn (array $match): string => '(?i:'.$match[0].')', preg_quote($secret, '~'));
            $candidate = preg_replace_callback('~'.$pattern.'~', static fn (): string => $policy->replacement, $redacted);
            if (! is_string($candidate)) {
                throw new \RuntimeException('Credential redaction failed.');
            }
            $redacted = $candidate;
        }
        foreach ($policy->patterns as $pattern) {
            $candidate = preg_replace($pattern, $policy->replacement, $redacted);
            if (! is_string($candidate)) {
                throw new \RuntimeException('A configured redaction pattern failed.');
            }
            $redacted = $candidate;
        }

        $candidate = preg_replace_callback(
            '~https?://[^\s"\'<>]+~iu',
            static fn (array $match): string => EvidenceUrl::sanitize($match[0]) ?? $policy->replacement,
            $redacted,
        );
        if (! is_string($candidate)) {
            throw new \RuntimeException('URL redaction failed.');
        }

        return $candidate;
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @return array<string, list<string>>
     */
    private function headers(array $headers, RedactionPolicy $policy): array
    {
        $safe = [];
        foreach ($headers as $name => $values) {
            $normalized = strtolower($name);
            if (! in_array($normalized, self::SAFE_HEADERS, true)
                && ! str_starts_with($normalized, 'x-ratelimit-')
                && ! str_starts_with($normalized, 'ratelimit-')) {
                continue;
            }

            $safe[$normalized] = array_map(
                fn (string $value): string => $this->redactString($value, $policy),
                $values,
            );
        }

        ksort($safe);

        return $safe;
    }

    public static function containsCredential(string $candidate, ?string $credential): bool
    {
        if ($credential === null || $credential === '') {
            return false;
        }

        $uri = rawurldecode($candidate);
        $form = urldecode($candidate);
        foreach (self::credentialForms($credential) as $secret) {
            if (str_contains($candidate, $secret) || str_contains($uri, $secret) || str_contains($form, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Profile>  $profiles
     * @return list<string>
     */
    private function secrets(Collection $profiles): array
    {
        $values = $profiles
            ->pluck('credential')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $secrets = [];
        foreach ($values as $value) {
            foreach (self::credentialForms($value) as $form) {
                $secrets[$form] = true;
            }
        }

        $result = array_keys($secrets);
        usort($result, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $result;
    }

    /** @return list<string> */
    private static function credentialForms(string $credential): array
    {
        $values = [$credential];
        $decoded = base64_decode($credential, true);
        if (is_string($decoded) && $decoded !== '' && base64_encode($decoded) === $credential) {
            array_push($values, $decoded, ...explode(':', $decoded));
        }
        $forms = [];
        foreach (array_filter($values, static fn (string $value): bool => $value !== '') as $value) {
            $base64 = base64_encode($value);
            $base64url = strtr($base64, '+/', '-_');
            $uri = rawurlencode($value);
            $component = strtr($uri, ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
            array_push($forms, $value, $uri, $component, urlencode($value), $base64, rtrim($base64, '='), $base64url, rtrim($base64url, '='));
        }

        return array_values(array_unique($forms));
    }

    private function sensitiveKey(string $key): bool
    {
        $words = preg_split('/[^a-z0-9]+/', Str::snake($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $compact = implode('', $words);

        return array_intersect($words, [
            'authorization', 'cookie', 'credential', 'password', 'secret', 'session', 'signature', 'token',
        ]) !== []
            || preg_match('/(?:apikey|apitoken|accesskey|accesstoken|authtoken|bearertoken|refreshtoken|sessiontoken|signedurl|presignedurl)$/D', $compact) === 1;
    }

    /** @param array<string, list<string>> $headers */
    private function firstHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $values) {
            if (strtolower($header) === $name) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    private static function invalidPattern(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') === false;
        } finally {
            restore_error_handler();
        }
    }
}
