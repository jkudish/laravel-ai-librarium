<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\Enums\FailureCategory;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\Enums\SubmissionState;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Throwable;

/** @internal */
final class CaptureSession
{
    private const string HTTP_DATE_PATTERN = '/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), \d{2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4} \d{2}:\d{2}:\d{2} GMT$/D';

    /** @var list<SensitiveProviderResponse> */
    private array $responses = [];

    /**
     * @var list<array{
     *     provider: string,
     *     profile: string,
     *     operation: Operation,
     *     response: SensitiveProviderResponse|null
     * }>
     */
    private array $attempts = [];

    /** @var list<OperationalDiagnostic> */
    private array $diagnostics = [];

    private bool $partial = false;

    private bool $unavailable = false;

    public function __construct(private readonly CarbonImmutable $startedAt) {}

    public function capture(
        Profile $profile,
        Response $response,
        Operation $operation,
        ?string $body = null,
    ): void {
        $captured = new SensitiveProviderResponse(
            provider: $profile->provider,
            profile: $profile->id,
            operation: $operation,
            status: $response->status(),
            headers: $this->headers($response),
            body: $body ?? $response->body(),
            receivedAt: CarbonImmutable::now(),
        );

        $this->responses[] = $captured;
        $this->attempts[] = [
            'provider' => $profile->provider,
            'profile' => $profile->id,
            'operation' => $operation,
            'response' => $captured,
        ];
    }

    public function noResponse(Profile $profile, Operation $operation): void
    {
        $this->attempts[] = [
            'provider' => $profile->provider,
            'profile' => $profile->id,
            'operation' => $operation,
            'response' => null,
        ];
    }

    public function markPartial(): void
    {
        $this->partial = true;
    }

    public function markUnavailable(): void
    {
        $this->unavailable = true;
    }

    public function attemptCount(): int
    {
        return count($this->attempts);
    }

    public function failure(
        Profile $profile,
        DriverException $exception,
        Operation $defaultOperation = Operation::Inline,
    ): void {
        $attempt = $this->lastAttempt($profile);
        $response = $attempt['response'] ?? null;
        $operation = $attempt['operation'] ?? $defaultOperation;
        $status = $response?->status();

        $this->diagnostics[] = new OperationalDiagnostic(
            provider: $profile->provider,
            profile: $profile->id,
            code: $exception->errorCode,
            category: $this->category($exception->errorCode, $status),
            operation: $operation,
            submissionState: $this->submissionState($operation, $status, $attempt !== null, $exception->errorCode),
            httpStatus: $status,
            providerCode: $response === null ? null : $this->providerCode($response->body(), $profile->credential),
            retryAt: $response === null ? null : $this->retryAt($response),
            resetAt: $response === null ? null : $this->resetAt($response),
        );
    }

    public function finish(ResearchResponse $response): SensitiveCollection
    {
        return new SensitiveCollection(
            response: $response,
            coverage: $this->coverage(),
            responses: $this->responses(),
            diagnostics: $this->diagnostics(),
            startedAt: $this->startedAt(),
        );
    }

    public function coverage(): CaptureCoverage
    {
        if ($this->partial || ($this->unavailable && ($this->responses !== [] || $this->attempts !== []))) {
            return CaptureCoverage::Partial;
        }

        if ($this->responses !== []) {
            return CaptureCoverage::Complete;
        }

        return $this->unavailable || $this->attempts === []
            ? CaptureCoverage::Unavailable
            : CaptureCoverage::NoResponse;
    }

    /** @return list<SensitiveProviderResponse> */
    public function responses(): array
    {
        return $this->responses;
    }

    /** @return Collection<int, OperationalDiagnostic> */
    public function diagnostics(): Collection
    {
        return collect($this->diagnostics);
    }

    public function lastDiagnostic(): ?OperationalDiagnostic
    {
        return $this->diagnostics === []
            ? null
            : $this->diagnostics[count($this->diagnostics) - 1];
    }

    public function startedAt(): CarbonImmutable
    {
        return $this->startedAt;
    }

    /**
     * @return array{
     *     provider: string,
     *     profile: string,
     *     operation: Operation,
     *     response: SensitiveProviderResponse|null
     * }|null
     */
    private function lastAttempt(Profile $profile): ?array
    {
        for ($index = count($this->attempts) - 1; $index >= 0; $index--) {
            if ($this->attempts[$index]['profile'] === $profile->id) {
                return $this->attempts[$index];
            }
        }

        return null;
    }

    private function category(string $code, ?int $status): FailureCategory
    {
        if ($status === 429 || str_contains($code, 'rate_limit')) {
            return FailureCategory::RateLimit;
        }

        if (($status !== null && ($status === 408 || $status === 425 || $status >= 500))
            || preg_match('/(?:connection|deadline|network|timeout|unavailable|overloaded)/', $code) === 1) {
            return FailureCategory::Temporary;
        }

        if (($status !== null && $status >= 400)
            || preg_match('/(?:authentication|authorization|billing|cancelled|credits|failed|forbidden|incomplete|invalid|missing|quota)/', $code) === 1) {
            return FailureCategory::Permanent;
        }

        return FailureCategory::Unknown;
    }

    private function submissionState(
        Operation $operation,
        ?int $status,
        bool $attempted,
        string $code,
    ): SubmissionState {
        if (! $attempted) {
            return SubmissionState::NotSent;
        }

        if (in_array($operation, [Operation::Poll, Operation::Retrieve, Operation::Cancel], true)) {
            return SubmissionState::Accepted;
        }

        if ($status === null || $status === 408 || $status === 425 || $status === 429 || $status >= 500) {
            return SubmissionState::PossiblyAccepted;
        }

        if ($operation === Operation::Submit
            && $status >= 200
            && $status < 400
            && preg_match('/(?:ambiguous|invalid_response|unknown)/', $code) === 1) {
            return SubmissionState::PossiblyAccepted;
        }

        if ($status >= 200 && $status < 400) {
            return SubmissionState::Accepted;
        }

        return SubmissionState::Rejected;
    }

    private function providerCode(string $body, ?string $credential): ?string
    {
        try {
            $value = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['error.code', 'error.type', 'code', 'status.code'] as $path) {
            $candidate = data_get($value, $path);
            if (is_string($candidate)
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,254}$/D', $candidate) === 1
                && ! $this->containsCredential($candidate, $credential)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<string, list<string>> */
    private function headers(Response $response): array
    {
        $headers = [];
        foreach ($response->headers() as $name => $values) {
            if (! is_string($name) || ! is_array($values)) {
                continue;
            }

            $strings = array_values(array_filter($values, is_string(...)));
            if ($strings !== []) {
                $headers[$name] = $strings;
            }
        }

        return $headers;
    }

    private function retryAt(SensitiveProviderResponse $response): ?CarbonImmutable
    {
        $value = $this->header($response, 'retry-after');
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{1,9}$/D', $value) === 1) {
            return $response->receivedAt()->addSeconds((int) $value);
        }

        if (preg_match(self::HTTP_DATE_PATTERN, $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function resetAt(SensitiveProviderResponse $response): ?CarbonImmutable
    {
        foreach (['x-ratelimit-reset', 'ratelimit-reset', 'x-rate-limit-reset'] as $name) {
            $value = $this->header($response, $name);
            if ($value === null) {
                continue;
            }

            if (preg_match('/^\d{10}$/D', $value) === 1) {
                return CarbonImmutable::createFromTimestampUTC((int) $value);
            }

            if (preg_match('/^\d{13}$/D', $value) === 1) {
                return CarbonImmutable::createFromTimestampMsUTC((int) $value);
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1
                && preg_match(self::HTTP_DATE_PATTERN, $value) !== 1) {
                continue;
            }

            try {
                return CarbonImmutable::parse($value)->utc();
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function header(SensitiveProviderResponse $response, string $name): ?string
    {
        foreach ($response->headers() as $header => $values) {
            if (strtolower($header) !== $name || count($values) !== 1) {
                continue;
            }

            $value = trim($values[0]);

            return $value === '' || strlen($value) > 255 ? null : $value;
        }

        return null;
    }

    private function containsCredential(string $candidate, ?string $credential): bool
    {
        if ($credential === null || $credential === '') {
            return false;
        }

        $forms = [$credential, rawurlencode($credential), urlencode($credential), base64_encode($credential)];
        $decoded = base64_decode($credential, true);
        if (is_string($decoded) && $decoded !== '' && base64_encode($decoded) === $credential) {
            $forms[] = $decoded;
            array_push($forms, ...explode(':', $decoded));
        }

        foreach ($forms as $secret) {
            if ($secret !== '' && str_contains($candidate, $secret)) {
                return true;
            }
        }

        return false;
    }
}
