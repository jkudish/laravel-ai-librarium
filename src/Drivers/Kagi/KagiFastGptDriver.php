<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Kagi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

/**
 * Laravel HTTP Driver for Kagi's inline FastGPT grounded-answer endpoint.
 */
final readonly class KagiFastGptDriver implements DefinesSpendingBound, Driver
{
    private const string ENDPOINT = 'https://kagi.com/api/v0/fastgpt';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $request->profile->credential;
        if (blank($credential)) {
            throw new DriverException(
                'kagi.authentication',
                'The Kagi FastGPT provider is not configured.',
            );
        }

        $response = $this->send($request, (string) $credential);
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException(
                'kagi.deadline_exceeded',
                'Kagi FastGPT did not respond before the research deadline.',
            );
        }

        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        if (filled(data_get($json, 'error'))) {
            throw new DriverException(
                'kagi.provider',
                'Kagi FastGPT reported an error for this request.',
            );
        }

        return $this->result($request, $json);
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        if ($request->profile->id !== 'kagi-fastgpt' || $request->profile->options !== []) {
            return SpendingBound::unknown();
        }

        return SpendingBound::billableQuantities(
            new ModelIdentity('kagi', 'fastgpt'),
            new PricingUsage(['uncached_queries' => 1]),
        );
    }

    private function send(DriverRequest $request, string $credential): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withHeaders(['Authorization' => "Bot {$credential}"])
                ->acceptJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout($remaining)
                ->post(self::ENDPOINT, ['query' => $request->prompt]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException(
                    'kagi.deadline_exceeded',
                    'Kagi FastGPT did not respond before the research deadline.',
                );
            }

            throw new DriverException(
                'kagi.connection',
                'Kagi FastGPT could not be reached.',
            );
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds <= 0) {
            throw new DriverException(
                'kagi.deadline_exceeded',
                'The Kagi FastGPT request could not start before the research deadline.',
            );
        }

        return $seconds;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('kagi.authentication', 'Kagi FastGPT rejected the configured credential.'),
            $response->status() === 403 => new DriverException('kagi.authorization', 'Kagi FastGPT denied this request.'),
            $response->status() === 402 => new DriverException('kagi.insufficient_credit', 'Kagi FastGPT could not run because the account has insufficient API credit.'),
            $response->status() === 408 => new DriverException('kagi.timeout', 'Kagi FastGPT timed out while processing this request.'),
            $response->status() === 429 => new DriverException('kagi.rate_limited', 'Kagi FastGPT rate limited this request.'),
            $response->serverError() => new DriverException('kagi.unavailable', 'Kagi FastGPT is temporarily unavailable.'),
            default => new DriverException('kagi.invalid_request', 'Kagi FastGPT rejected this request.', false),
        };
    }

    /** @param array<array-key, mixed> $json */
    private function result(DriverRequest $request, array $json): ResearchResult
    {
        $data = data_get($json, 'data');
        if (! is_array($data) || array_is_list($data)) {
            throw $this->invalidResponse();
        }

        $output = $this->str(data_get($data, 'output'));
        $references = data_get($data, 'references');
        if ($output === null || ! is_array($references) || ! array_is_list($references)) {
            throw $this->invalidResponse();
        }

        $citations = $this->citations($references);
        if ($citations->isEmpty()) {
            throw $this->invalidResponse();
        }

        $usage = $this->usage($data);
        $completedAt = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $output,
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $completedAt,
            ),
            citations: $citations,
            completedAt: $completedAt,
            usage: $usage,
        );
    }

    /**
     * @param  list<mixed>  $references
     * @return Collection<int, Citation>
     */
    private function citations(array $references): Collection
    {
        $citations = [];
        $seen = [];

        foreach ($references as $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $url = $this->evidenceUrl(data_get($reference, 'url'));
            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $snippet = $this->str(data_get($reference, 'snippet'));
            $citations[] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $this->str(data_get($reference, 'title')),
                ),
                excerpt: $snippet === null ? null : Str::limit($snippet, 200, ''),
            );
        }

        return collect($citations);
    }

    /** @param array<array-key, mixed> $data */
    private function usage(array $data): ?Usage
    {
        if (! array_key_exists('tokens', $data)) {
            return null;
        }

        $tokens = $data['tokens'];
        if (! is_int($tokens) || $tokens < 0 || $tokens > 9_007_199_254_740_991) {
            throw $this->invalidResponse();
        }

        return new Usage(completionTokens: $tokens);
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
                ? $value
                : null;
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'kagi.invalid_response',
            'Kagi FastGPT returned an invalid grounded answer.',
        );
    }
}
