<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\You;

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

/** Laravel HTTP Driver for inline, standard-effort You.com Research answers. */
final readonly class YouResearchGroundedDriver implements Driver
{
    private const string ENDPOINT = 'https://api.you.com/v1/research';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    public function __construct(private YouResearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'research_effort' => ['required', 'string', 'in:standard'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $this->preflight($request);
        $response = $this->send($request, $credential);
        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        return $this->mapper->map($request, $json, false);
    }

    private function preflight(DriverRequest $request): string
    {
        $profile = $request->profile;
        if ($profile->provider !== 'you-research'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::GroundedAnswer
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException(
                'you_research.invalid_profile',
                'The You.com Research Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if ($profile->options !== ['research_effort' => 'standard']
            || trim($request->prompt) === ''
            || mb_strlen($request->prompt) > 40_000) {
            throw new DriverException(
                'you_research.invalid_request',
                'The You.com Research input or options are invalid.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException(
                'you_research.authentication',
                'The You.com Research provider is not configured.',
                false,
            );
        }

        $this->remainingSeconds($request);

        return (string) $profile->credential;
    }

    private function send(DriverRequest $request, string $credential): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withHeaders(['X-API-Key' => $credential])
                ->withoutRedirecting()
                ->acceptJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout($remaining)
                ->post(self::ENDPOINT, [
                    'input' => $request->prompt,
                    'research_effort' => 'standard',
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException(
                'you_research.connection',
                'You.com Research could not be reached.',
                false,
            );
        }

        $request->captureResponse($response, Operation::Inline);
        $this->remainingSeconds($request);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw $this->deadlineExceeded();
        }

        return $seconds;
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'you_research.deadline_exceeded',
            'You.com Research did not complete before the research deadline.',
            false,
        );
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('you_research.authentication', 'You.com Research rejected the configured credential.', false),
            $response->status() === 402 => new DriverException('you_research.insufficient_credit', 'You.com Research could not run because the account has insufficient API credit.', false),
            $response->status() === 403 => new DriverException('you_research.authorization', 'You.com Research denied this request.', false),
            $response->status() === 408 => new DriverException('you_research.timeout', 'You.com Research timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('you_research.rate_limited', 'You.com Research rate limited this request.', false),
            $response->serverError() => new DriverException('you_research.unavailable', 'You.com Research is temporarily unavailable.', false),
            default => new DriverException('you_research.invalid_request', 'You.com Research rejected this request.', false),
        };
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'you_research.invalid_response',
            'You.com Research returned an invalid grounded answer.',
            false,
        );
    }
}
