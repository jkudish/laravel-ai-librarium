<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Parallel;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;

/** @internal */
final readonly class ParallelTransport
{
    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 25.0;

    /** @param array<string, mixed> $payload */
    public function post(
        DriverRequest $request,
        string $url,
        array $payload,
        bool $submission = false,
        bool $enforcePostResponseDeadline = true,
    ): Response {
        return $this->send(
            $request,
            'post',
            $url,
            $payload,
            $submission,
            $submission,
            $submission ? Operation::Submit : Operation::Inline,
            $enforcePostResponseDeadline,
        );
    }

    public function get(DriverRequest|RecoveryRequest $request, string $url, Operation $operation = Operation::Poll): Response
    {
        return $this->send($request, 'get', $url, [], false, true, $operation, true);
    }

    /** @param array<string, mixed> $payload */
    private function send(
        DriverRequest|RecoveryRequest $request,
        string $method,
        string $url,
        array $payload,
        bool $submission,
        bool $durable,
        Operation $operation,
        bool $enforcePostResponseDeadline,
    ): Response {
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
            $request->captureNoResponse($operation);

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

            throw new DriverException('parallel.connection', 'Parallel could not be reached.', ! $durable);
        }

        $request->captureResponse($response, $operation);

        if ($enforcePostResponseDeadline && CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
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

    public function failure(Response $response, bool $durable = false): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('parallel.authentication', 'Parallel rejected the configured credential.', ! $durable),
            $response->status() === 402 => new DriverException('parallel.insufficient_credit', 'The Parallel account has insufficient API credit.', ! $durable),
            $response->status() === 403 => new DriverException('parallel.authorization', 'Parallel denied this request.', ! $durable),
            $response->status() === 404 && $durable => new DriverException('parallel.run_missing', 'The Parallel Research run is no longer available.', false),
            $response->status() === 408 => new DriverException('parallel.timeout', 'Parallel timed out while processing this request.', ! $durable),
            $response->status() === 429 => new DriverException('parallel.rate_limited', 'Parallel rate limited this request.', ! $durable),
            $response->serverError() => new DriverException('parallel.unavailable', 'Parallel is temporarily unavailable.', ! $durable),
            default => new DriverException('parallel.invalid_request', 'Parallel rejected this request.', false),
        };
    }

    public function remainingSeconds(DriverRequest|RecoveryRequest $request): float
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
            false,
        );
    }
}
