<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Tavily;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
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

/** Laravel HTTP Driver for the synchronous Tavily Search API. */
final readonly class TavilySearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.tavily.com/search';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateProfile($request);
        $this->requireCredential($request);
        $json = $this->requestJson($request);
        $results = $this->results($json);
        $answer = $this->str(data_get($json, 'answer'));
        $parts = [];

        if ($answer !== null) {
            $parts[] = "## Answer\n";
            $parts[] = $this->markdownText($answer);
            $parts[] = '';
        }

        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $renderedResults = [];
        $seen = [];

        foreach ($results as $result) {
            $url = $this->evidenceUrl(data_get($result, 'url'));
            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $reportedTitle = $this->str(data_get($result, 'title'));
            $content = $this->str(data_get($result, 'content'));
            $renderedResults[] = [
                'title' => $reportedTitle ?? 'Untitled',
                'url' => $url,
                'content' => $content,
            ];
            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $reportedTitle,
                ),
                excerpt: $content === null ? null : mb_substr($content, 0, 200),
            ));
        }

        if ($renderedResults !== []) {
            $parts[] = "## Search Results\n";
            foreach ($renderedResults as $result) {
                $parts[] = "### [{$this->markdownInline($result['title'])}]({$this->markdownUrl($result['url'])})";
                if ($result['content'] !== null) {
                    $parts[] = $this->markdownText($result['content']);
                }
                $parts[] = '';
            }
        }

        $now = CarbonImmutable::now();
        $providerMeta = ['result_count' => count($results)];
        $credits = $this->credits(data_get($json, 'usage.credits'));
        if ($credits !== null) {
            $providerMeta['credits'] = $credits;
        }

        return TerminalRedaction::guard(ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: implode("\n", $parts),
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: $citations,
            completedAt: $now,
            providerMeta: (object) $providerMeta,
        ), [(string) $request->profile->credential], $this->invalidResponse());
    }

    /** @return array<array-key, mixed> */
    private function requestJson(DriverRequest $request): array
    {
        $response = $this->send($request);
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException('tavily.deadline_exceeded', 'Tavily did not respond before the research deadline.');
        }

        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        if (filled(data_get($json, 'error'))) {
            throw new DriverException('tavily.provider', 'Tavily reported an error for this request.');
        }

        return $json;
    }

    private function send(DriverRequest $request): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withToken((string) $request->profile->credential)
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->post(self::ENDPOINT, [
                    'query' => $request->prompt,
                    'search_depth' => 'advanced',
                    'include_answer' => true,
                    'max_results' => 10,
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('tavily.deadline_exceeded', 'Tavily did not respond before the research deadline.');
            }

            throw new DriverException('tavily.connection', 'Tavily could not be reached.');
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 408 => new DriverException('tavily.timeout', 'Tavily timed out before completing this request.'),
            $response->status() === 401 => new DriverException('tavily.authentication', 'Tavily rejected the configured credential.'),
            $response->status() === 403 => new DriverException('tavily.authorization', 'Tavily denied this request.'),
            in_array($response->status(), [402, 432, 433], true) => new DriverException('tavily.quota_exceeded', 'The Tavily account cannot run this request because its usage limit was reached.'),
            $response->status() === 429 => new DriverException('tavily.rate_limited', 'Tavily rate limited this request.'),
            $response->status() >= 500 => new DriverException('tavily.unavailable', 'Tavily is temporarily unavailable.'),
            default => new DriverException('tavily.invalid_request', 'Tavily rejected this request.', false),
        };
    }

    private function requireCredential(DriverRequest $request): void
    {
        if (blank($request->profile->credential)) {
            throw new DriverException('tavily.authentication', 'The Tavily provider is not configured.');
        }
    }

    private function validateProfile(DriverRequest $request): void
    {
        $profile = $request->profile;
        if ($profile->id !== 'tavily-search'
            || $profile->provider !== 'tavily'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
            || $profile->options !== []
            || trim($request->prompt) === '') {
            throw new DriverException(
                'tavily.invalid_profile',
                'The Tavily Search Profile has incompatible identity or provenance semantics.',
                false,
            );
        }
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 0.001) {
            throw new DriverException('tavily.deadline_exceeded', 'The Tavily request could not start before the research deadline.');
        }

        return $seconds;
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return list<array<array-key, mixed>>
     */
    private function results(array $json): array
    {
        $results = data_get($json, 'results');
        if (! is_array($results) || ! array_is_list($results)) {
            throw $this->invalidResponse();
        }

        return array_values(array_filter($results, static fn (mixed $result): bool => is_array($result) && ! array_is_list($result)));
    }

    private function credits(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 9_007_199_254_740_991
            ? $value
            : null;
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

    private function invalidResponse(): DriverException
    {
        return new DriverException('tavily.invalid_response', 'Tavily returned a malformed response.');
    }
}
