<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\SearchApi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use stdClass;

/**
 * A small Laravel HTTP Driver for the SearchAPI.io endpoint.
 *
 * Every profile targets one built-in engine and, for consumer surfaces, one
 * fixed collector/surface identity. Credentials use bearer authentication and
 * never enter the URL, progress, errors, provider metadata, or results.
 */
final readonly class SearchApiDriver implements Driver
{
    private const string ENDPOINT = 'https://www.searchapi.io/api/v1/search';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    /**
     * Truthful, built-in engine and surface identity for each shipped profile.
     * Surfaces are answered by consumer AI products that SearchAPI collects.
     *
     * @var array<string, array{mode: string, engine?: string, surface?: string, parameters?: array<string, scalar>}>
     */
    private const array PROFILES = [
        'searchapi' => ['mode' => 'search', 'engine' => 'google'],
        'searchapi-chatgpt' => ['mode' => 'surface', 'engine' => 'chatgpt', 'surface' => 'chatgpt', 'parameters' => ['web_search' => 'true']],
        'searchapi-gemini' => ['mode' => 'surface', 'engine' => 'gemini', 'surface' => 'gemini'],
        'searchapi-perplexity' => ['mode' => 'surface', 'engine' => 'perplexity', 'surface' => 'perplexity'],
        'searchapi-google-ai-mode' => ['mode' => 'surface', 'engine' => 'google_ai_mode', 'surface' => 'google_ai_mode'],
        'searchapi-bing-copilot' => ['mode' => 'surface', 'engine' => 'bing_copilot', 'surface' => 'bing_copilot'],
        'searchapi-google-ai-overview' => ['mode' => 'overview', 'surface' => 'google_ai_overview'],
    ];

    public function __construct(private SearchApiResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'zero_retention' => ['sometimes', 'boolean'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $descriptor = $this->descriptor($request->profile->id);
        $zeroRetention = $this->zeroRetention($request);
        $this->requireCredential($request, $zeroRetention);

        return match ($descriptor['mode']) {
            'search' => $this->runSearch($request, $descriptor['engine'] ?? 'google', $zeroRetention),
            'surface' => $this->runSurface($request, $descriptor, $zeroRetention),
            'overview' => $this->runOverview($request, $zeroRetention),
            default => throw $this->fail('searchapi.unsupported_profile', 'The requested SearchAPI profile is not supported.', $zeroRetention, false),
        };
    }

    /**
     * @return array{mode: string, engine?: string, surface?: string, parameters?: array<string, scalar>}
     */
    private function descriptor(string $profileId): array
    {
        return self::PROFILES[$profileId]
            ?? throw new DriverException('searchapi.unsupported_profile', 'The requested SearchAPI profile is not supported.', false);
    }

    private function runSearch(DriverRequest $request, string $engine, bool $zeroRetention): ResearchResult
    {
        $json = $this->requestJson($request, $engine, ['q' => $request->prompt], $zeroRetention);
        $normalized = $this->mapper->normalizeGoogle($json);

        return $this->mapper->result(
            request: $request,
            content: $normalized['content'],
            citations: $normalized['citations'],
            surface: null,
            model: null,
            providerMeta: $this->providerMeta($json, count($this->safeList(data_get($json, 'organic_results')))),
        );
    }

    /**
     * @param  array{mode: string, engine?: string, surface?: string, parameters?: array<string, scalar>}  $descriptor
     */
    private function runSurface(DriverRequest $request, array $descriptor, bool $zeroRetention): ResearchResult
    {
        $parameters = ['q' => $request->prompt, ...($descriptor['parameters'] ?? [])];
        $json = $this->requestJson($request, $descriptor['engine'] ?? '', $parameters, $zeroRetention);

        return $this->surfaceResult($request, $json, $descriptor['surface'] ?? '', $zeroRetention);
    }

    private function runOverview(DriverRequest $request, bool $zeroRetention): ResearchResult
    {
        $first = $this->requestJson($request, 'google', ['q' => $request->prompt], $zeroRetention);
        $pageToken = $this->str(data_get($first, 'ai_overview.page_token'));
        if ($pageToken === null) {
            throw $this->fail('searchapi.invalid_response', 'SearchAPI did not return a Google AI Overview page token.', $zeroRetention);
        }

        $second = $this->requestJson($request, 'google_ai_overview', ['page_token' => $pageToken], $zeroRetention);

        return $this->surfaceResult($request, $second, 'google_ai_overview', $zeroRetention);
    }

    /**
     * @param  array<array-key, mixed>  $json
     */
    private function surfaceResult(DriverRequest $request, array $json, string $surface, bool $zeroRetention): ResearchResult
    {
        $normalized = $this->mapper->normalizeSurface($json);
        if ($normalized['content'] === '') {
            throw $this->fail('searchapi.invalid_response', 'SearchAPI returned no answer for this surface.', $zeroRetention);
        }

        return $this->mapper->result(
            request: $request,
            content: $normalized['content'],
            citations: $normalized['citations'],
            surface: $surface,
            model: $normalized['model'],
            providerMeta: $this->providerMeta($json, null),
        );
    }

    /**
     * @param  array<string, scalar>  $parameters
     * @return array<array-key, mixed>
     */
    private function requestJson(DriverRequest $request, string $engine, array $parameters, bool $zeroRetention): array
    {
        $response = $this->send($request, $engine, $parameters, $zeroRetention);

        if ($response->failed()) {
            throw $this->httpFailure($response, $zeroRetention);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw $this->fail('searchapi.invalid_response', 'SearchAPI returned a malformed response.', $zeroRetention);
        }

        if (filled(data_get($json, 'error'))) {
            throw $this->fail('searchapi.provider', 'SearchAPI reported an error for this request.', $zeroRetention);
        }

        return $json;
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function send(DriverRequest $request, string $engine, array $parameters, bool $zeroRetention): Response
    {
        $remaining = $this->remainingSeconds($request, $zeroRetention);

        $query = ['engine' => $engine, ...$parameters];
        if ($zeroRetention) {
            $query['zero_retention'] = 'true';
        }

        try {
            $response = Http::withToken((string) $request->profile->credential)
                ->acceptJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->get(self::ENDPOINT, $query);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->fail('searchapi.deadline_exceeded', 'SearchAPI did not respond before the research deadline.', $zeroRetention);
            }

            throw $this->fail('searchapi.connection', 'SearchAPI could not be reached.', $zeroRetention);
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function httpFailure(Response $response, bool $zeroRetention): DriverException
    {
        $status = $response->status();

        if ($zeroRetention && $this->deniedZeroRetention($status, $response)) {
            return new DriverException(
                'searchapi.zero_retention_unsupported',
                'SearchAPI rejected the required zero-retention request. Rerun without zero retention or upgrade the SearchAPI account.',
                false,
            );
        }

        return match (true) {
            $status === 401 => $this->fail('searchapi.authentication', 'SearchAPI rejected the configured credential.', $zeroRetention),
            $status === 403 => $this->fail('searchapi.authorization', 'SearchAPI denied this request.', $zeroRetention),
            $status === 429 => $this->fail('searchapi.rate_limited', 'SearchAPI rate limited this request.', $zeroRetention),
            $status >= 500 => $this->fail('searchapi.unavailable', 'SearchAPI is temporarily unavailable.', $zeroRetention),
            default => new DriverException('searchapi.invalid_request', 'SearchAPI rejected this request.', false),
        };
    }

    private function deniedZeroRetention(int $status, Response $response): bool
    {
        if (! in_array($status, [400, 402, 403, 422], true)) {
            return false;
        }

        $body = $response->body();
        if (preg_match('/\bzero[_ -](?:data[_ -])?retention\b/i', $body) !== 1) {
            return false;
        }

        return preg_match(
            '/\b(?:enterprise|entitlement|upgrade|unsupported|unavailable)\b|\bnot (?:supported|available|enabled)\b|\bonly available\b|\brequires? (?:an? )?(?:enterprise|eligible|paid) (?:plan|account)\b|\bcurrent (?:plan|account) (?:does not|doesn\'t|cannot|can\'t)\b/i',
            $body,
        ) === 1;
    }

    private function zeroRetention(DriverRequest $request): bool
    {
        $value = data_get($request->profile->options, 'zero_retention', false);
        if (! is_bool($value)) {
            throw new DriverException('searchapi.invalid_options', 'The SearchAPI profile received invalid options.', false);
        }

        return $value;
    }

    private function requireCredential(DriverRequest $request, bool $zeroRetention): void
    {
        if (blank($request->profile->credential)) {
            throw $this->fail('searchapi.authentication', 'The SearchAPI provider is not configured.', $zeroRetention);
        }
    }

    private function remainingSeconds(DriverRequest $request, bool $zeroRetention): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 1) {
            throw $this->fail('searchapi.deadline_exceeded', 'The SearchAPI request could not start before the research deadline.', $zeroRetention);
        }

        return max(1, (int) floor($seconds));
    }

    /**
     * @param  array<array-key, mixed>  $json
     */
    private function providerMeta(array $json, ?int $resultCount): ?stdClass
    {
        $meta = [];
        $requestId = $this->str(data_get($json, 'search_metadata.id'));
        if ($requestId !== null) {
            $meta['request_id'] = $requestId;
        }
        if ($resultCount !== null) {
            $meta['result_count'] = $resultCount;
        }

        return $meta === [] ? null : (object) $meta;
    }

    /**
     * @return list<mixed>
     */
    private function safeList(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private function fail(string $code, string $message, bool $zeroRetention, bool $fallbackAllowed = true): DriverException
    {
        return new DriverException($code, $message, $zeroRetention ? false : $fallbackAllowed);
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
