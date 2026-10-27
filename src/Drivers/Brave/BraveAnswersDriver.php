<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Brave;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Throwable;

/**
 * Runs one grounded Brave Answers request through Laravel's HTTP client.
 *
 * This driver deliberately does not implement DefinesSpendingBound: no
 * documented complete pre-dispatch bound is established for this exact
 * request. Brave's official pricing bills every answer as searches plus
 * input and output tokens; the shipped single-search payload serializes no
 * control that caps all of those dimensions, and adding the provider's
 * documented controls to this option-free Profile would still leave billed
 * input without a numeric maximum, while research mode drops this route's
 * enable_citations grounding and leaves aggregate mixer-iteration billing
 * undocumented. See docs/brave-answers-spending-bounds.md for the official
 * quotations and the provider guarantees that would be required first.
 * Keep classifying this Profile as `unknown` instead of promoting
 * estimates, qualitative controls, or partial token ceilings to a bound.
 */
final readonly class BraveAnswersDriver implements Driver
{
    private const string ENDPOINT = 'https://api.search.brave.com/res/v1/chat/completions';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    public function __construct(private BraveAnswersResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withHeaders([
                'X-Subscription-Token' => (string) $request->profile->credential,
            ])
                ->withoutRedirecting()
                ->accept('text/event-stream')
                ->asJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->withOptions(['stream' => true])
                ->post(self::ENDPOINT, [
                    'model' => 'brave',
                    'messages' => [[
                        'role' => 'user',
                        'content' => $request->prompt,
                    ]],
                    'stream' => true,
                    'enable_citations' => true,
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException('brave.connection', 'Brave Answers could not be reached.');
        }

        $this->remainingSeconds($request);

        if ($response->failed()) {
            throw $this->httpFailure($response, $request);
        }

        try {
            return $this->mapper->map($response, $request);
        } catch (DriverException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException('brave.connection', 'The Brave Answers stream was interrupted.');
        } catch (Throwable) {
            throw new DriverException('brave.invalid_response', 'Brave Answers returned an invalid response.');
        }
    }

    private function validateRequest(DriverRequest $request): void
    {
        $profile = $request->profile;

        if (
            $profile->id !== 'brave-answers'
            || $profile->provider !== 'brave'
            || $profile->model !== 'brave'
            || $profile->resultKind !== ResultKind::GroundedAnswer
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException(
                'brave.invalid_profile',
                'The Brave Answers Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException('brave.authentication', 'The Brave Answers provider is not configured.');
        }

        $this->remainingSeconds($request);
    }

    private function httpFailure(Response $response, DriverRequest $request): DriverException
    {
        $status = $response->status();
        $providerCode = $this->providerErrorCode($response, $request);

        return match (true) {
            $providerCode === 'OPTION_NOT_IN_PLAN' => new DriverException(
                'brave.plan_required',
                'The configured Brave account does not include the Answers plan.',
            ),
            $providerCode === 'SUBSCRIPTION_TOKEN_INVALID', $status === 401, $status === 403 => new DriverException(
                'brave.authentication',
                'Brave Answers rejected the configured credential.',
            ),
            $status === 402 => new DriverException('brave.billing', 'Brave Answers rejected this request for billing reasons.'),
            in_array($status, [408, 504], true) => $this->deadlineExceeded(),
            $status === 429 => new DriverException('brave.rate_limited', 'Brave Answers rate limited this request.'),
            $status >= 500 => new DriverException('brave.unavailable', 'Brave Answers is temporarily unavailable.'),
            default => new DriverException('brave.invalid_request', 'Brave Answers rejected this request.', false),
        };
    }

    private function providerErrorCode(Response $response, DriverRequest $request): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        try {
            while (! $stream->eof() && strlen($body) <= 262_144) {
                $chunk = $stream->read(min(8192, 262_145 - strlen($body)));
                if ($chunk === '') {
                    $stream->close();
                    $request->captureResponse($response, Operation::Inline, $body);
                    $request->markCapturePartial();

                    return null;
                }

                $body .= $chunk;
            }
        } catch (Throwable) {
            $request->captureResponse($response, Operation::Inline, $body);
            $request->markCapturePartial();

            return null;
        }

        if (strlen($body) > 262_144 || ! $stream->eof()) {
            $stream->close();
            $request->captureResponse($response, Operation::Inline, $body);
            $request->markCapturePartial();

            return null;
        }

        $request->captureResponse($response, Operation::Inline, $body);

        $json = json_decode($body, true);
        $code = is_array($json) ? data_get($json, 'error.code') : null;

        return is_string($code) ? $code : null;
    }

    private function remainingSeconds(DriverRequest $request): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 1) {
            throw $this->deadlineExceeded();
        }

        return max(1, (int) floor($seconds));
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'brave.deadline_exceeded',
            'Brave Answers did not respond before the research deadline.',
        );
    }
}
