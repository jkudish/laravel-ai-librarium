<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\SerpApi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

/** Runs one synchronous Google search through SerpAPI. */
final readonly class SerpApiDriver implements DefinesSpendingBound, Driver
{
    private const string ENDPOINT = 'https://serpapi.com/search';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 20.0;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateProfile($request);
        $credential = $request->profile->credential;
        if (blank($credential)) {
            throw new DriverException('serpapi.authentication', 'The SerpAPI provider is not configured.');
        }

        $response = $this->send($request, (string) $credential);
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }

        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || ($json !== [] && array_is_list($json))) {
            throw $this->invalidResponse();
        }

        if (filled(data_get($json, 'error'))) {
            throw new DriverException('serpapi.provider', 'SerpAPI reported an error for this request.');
        }

        return $this->result($request, $this->organicResults($json), (string) $credential);
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        if ($request->profile->id !== 'serpapi' || $request->profile->options !== []) {
            return SpendingBound::unknown();
        }

        return SpendingBound::billableQuantities(
            new ModelIdentity('serpapi', 'search'),
            new PricingUsage(['successful_search_request' => 1]),
        );
    }

    private function validateProfile(DriverRequest $request): void
    {
        $profile = $request->profile;
        if ($profile->id !== 'serpapi'
            || $profile->provider !== 'serpapi'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
            || $profile->options !== []
            || trim($request->prompt) === '') {
            throw new DriverException(
                'serpapi.invalid_profile',
                'The SerpAPI Profile has incompatible identity or provenance semantics.',
                false,
            );
        }
    }

    private function send(DriverRequest $request, string $credential): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->get(self::ENDPOINT, [
                    'engine' => 'google',
                    'q' => $request->prompt,
                    'api_key' => $credential,
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException('serpapi.connection', 'SerpAPI could not be reached.');
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds <= 0) {
            throw new DriverException('serpapi.deadline_exceeded', 'The SerpAPI request could not start before the research deadline.');
        }

        return $seconds;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('serpapi.authentication', 'SerpAPI rejected the configured credential.'),
            $response->status() === 403 => new DriverException('serpapi.authorization', 'SerpAPI denied this request.'),
            $response->status() === 408 => new DriverException('serpapi.timeout', 'SerpAPI timed out while processing this request.'),
            $response->status() === 429 => new DriverException('serpapi.rate_limited', 'SerpAPI rate limited this request or the account has no searches remaining.'),
            $response->serverError() => new DriverException('serpapi.unavailable', 'SerpAPI is temporarily unavailable.'),
            default => new DriverException('serpapi.invalid_request', 'SerpAPI rejected this request.', false),
        };
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return list<array<array-key, mixed>>
     */
    private function organicResults(array $json): array
    {
        $results = data_get($json, 'organic_results', []);
        if (! is_array($results) || ! array_is_list($results)) {
            throw $this->invalidResponse();
        }

        return array_values(array_filter(
            $results,
            static fn (mixed $result): bool => is_array($result) && ! array_is_list($result),
        ));
    }

    /** @param list<array<array-key, mixed>> $results */
    private function result(DriverRequest $request, array $results, string $credential): ResearchResult
    {
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $parts = [];
        $seen = [];

        foreach ($results as $result) {
            if ($this->containsCredential($result, $credential)) {
                throw $this->invalidResponse();
            }

            $url = $this->evidenceUrl(data_get($result, 'link'));
            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $reportedTitle = $this->str(data_get($result, 'title'));
            $snippet = $this->str(data_get($result, 'snippet'));
            $parts[] = '### ['.$this->markdownInline($reportedTitle ?? 'Untitled').']('.$this->markdownUrl($url).')';
            if ($snippet !== null) {
                $parts[] = $this->markdownText($snippet);
            }
            $parts[] = '';

            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $reportedTitle,
                ),
                excerpt: $snippet,
            ));
        }

        $completedAt = CarbonImmutable::now();

        return TerminalRedaction::guard(ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : implode("\n", $parts),
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
            providerMeta: (object) ['result_count' => count($results)],
        ), [(string) $request->profile->credential], $this->invalidResponse());
    }

    /** @param array<array-key, mixed> $result */
    private function containsCredential(array $result, string $credential): bool
    {
        foreach (['title', 'link', 'snippet'] as $field) {
            $value = data_get($result, $field);
            if (is_string($value) && str_contains($value, $credential)) {
                return true;
            }
        }

        return false;
    }

    private function evidenceUrl(mixed $value): ?string
    {
        return EvidenceUrl::sanitize($value);
    }

    private function markdownInline(string $value): string
    {
        return $this->markdownText(str_replace(["\r", "\n"], ' ', $value));
    }

    private function markdownText(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|', '~', '=', '^', '$'],
            ['\\\\', '\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\(', '\\)', '\\#', '\\+', '\\-', '\\.', '\\!', '\\|', '\\~', '\\=', '\\^', '\\$'],
            $escaped,
        );
    }

    private function markdownUrl(string $url): string
    {
        return str_replace(['\\', '(', ')'], ['%5C', '%28', '%29'], $url);
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException('serpapi.deadline_exceeded', 'SerpAPI did not respond before the research deadline.');
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('serpapi.invalid_response', 'SerpAPI returned a malformed search response.');
    }
}
