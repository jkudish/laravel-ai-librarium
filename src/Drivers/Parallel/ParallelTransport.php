<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Parallel;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;

/** @internal */
final readonly class ParallelTransport
{
    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 25.0;

    /** @param array<string, mixed> $payload */
    public function post(DriverRequest $request, string $url, array $payload, bool $submission = false): Response
    {
        return $this->send($request, 'post', $url, $payload, $submission);
    }

    public function get(DriverRequest $request, string $url): Response
    {
        return $this->send($request, 'get', $url, [], false);
    }

    /** @param array<string, mixed> $payload */
    private function send(DriverRequest $request, string $method, string $url, array $payload, bool $submission): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $pending = Http::withHeaders(['x-api-key' => (string) $request->profile->credential])
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

            $response = $method === 'post'
                ? $pending->post($url, $payload)
                : $pending->get($url);
        } catch (ConnectionException) {
            if ($submission) {
                throw new DriverException(
                    'parallel.ambiguous_submission',
                    'The Parallel Research submission outcome is unknown and was not retried.',
                    false,
                );
            }

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException('parallel.connection', 'Parallel could not be reached.');
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            if ($submission) {
                throw new DriverException(
                    'parallel.ambiguous_submission',
                    'Parallel did not acknowledge the Research submission before the deadline; it was not retried.',
                    false,
                );
            }

            throw $this->deadlineExceeded();
        }

        return $response;
    }

    public function failure(Response $response, bool $retrieval = false): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('parallel.authentication', 'Parallel rejected the configured credential.'),
            $response->status() === 402 => new DriverException('parallel.insufficient_credit', 'The Parallel account has insufficient API credit.'),
            $response->status() === 403 => new DriverException('parallel.authorization', 'Parallel denied this request.'),
            $response->status() === 404 && $retrieval => new DriverException('parallel.run_missing', 'The Parallel Research run is no longer available.', false),
            $response->status() === 408 => new DriverException('parallel.timeout', 'Parallel timed out while processing this request.'),
            $response->status() === 429 => new DriverException('parallel.rate_limited', 'Parallel rate limited this request.'),
            $response->serverError() => new DriverException('parallel.unavailable', 'Parallel is temporarily unavailable.'),
            default => new DriverException('parallel.invalid_request', 'Parallel rejected this request.', false),
        };
    }

    public function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw $this->deadlineExceeded();
        }

        return $seconds;
    }

    public function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'parallel.deadline_exceeded',
            'Parallel did not complete before the research deadline.',
        );
    }
}
