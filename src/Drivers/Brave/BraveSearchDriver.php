<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Brave;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
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

/** Runs one raw Brave Web Search request through Laravel's HTTP client. */
final readonly class BraveSearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 20.0;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $response = $this->send($request);

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded('Brave Search did not respond before the research deadline.');
        }

        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        $results = $this->results($json);
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $content = [];

        foreach ($results as $result) {
            $url = $this->evidenceUrl(data_get($result, 'url'));
            if ($url === null) {
                continue;
            }

            $reportedTitle = $this->str(data_get($result, 'title'));
            $description = $this->str(data_get($result, 'description'));
            $title = $this->markdownInline($reportedTitle ?? 'Untitled');

            $content[] = "- **[{$title}]({$this->markdownUrl($url)})**";
            if ($description !== null) {
                $content[] = '  '.$this->markdownText($description);
            }

            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $reportedTitle,
                ),
                excerpt: $description,
            ));
        }

        $completedAt = CarbonImmutable::now();

        return TerminalRedaction::guard(ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $content === [] ? 'No results found.' : implode("\n", $content),
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
            providerMeta: (object) ['result_count' => $citations->count()],
        ), [(string) $request->profile->credential], $this->invalidResponse());
    }

    private function validateRequest(DriverRequest $request): void
    {
        $profile = $request->profile;

        if (
            $profile->id !== 'brave-search'
            || $profile->provider !== 'brave-search'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException(
                'brave_search.invalid_profile',
                'The Brave Search Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException('brave_search.authentication', 'The Brave Search provider is not configured.');
        }

        $this->remainingSeconds($request);
    }

    private function send(DriverRequest $request): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            return Http::withHeaders([
                'X-Subscription-Token' => (string) $request->profile->credential,
            ])
                ->acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->get(self::ENDPOINT, ['q' => $request->prompt]);
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded('Brave Search did not respond before the research deadline.');
            }

            throw new DriverException('brave_search.connection', 'Brave Search could not be reached.');
        }
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            in_array($response->status(), [401, 403], true) => new DriverException('brave_search.authentication', 'Brave Search rejected the configured credential.'),
            $response->status() === 402 => new DriverException('brave_search.billing', 'Brave Search rejected this request for billing reasons.'),
            in_array($response->status(), [408, 504], true) => $this->deadlineExceeded('Brave Search timed out while processing this request.'),
            $response->status() === 429 => new DriverException('brave_search.rate_limited', 'Brave Search rate limited this request.'),
            $response->serverError() => new DriverException('brave_search.unavailable', 'Brave Search is temporarily unavailable.'),
            default => new DriverException('brave_search.invalid_request', 'Brave Search rejected this request.', false),
        };
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds <= 0) {
            throw $this->deadlineExceeded('The Brave Search request could not start before the research deadline.');
        }

        return $seconds;
    }

    private function deadlineExceeded(string $message): DriverException
    {
        return new DriverException('brave_search.deadline_exceeded', $message);
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('brave_search.invalid_response', 'Brave Search returned a malformed response.');
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return list<array<array-key, mixed>>
     */
    private function results(array $json): array
    {
        $results = data_get($json, 'web.results');
        if ($results === null) {
            return [];
        }

        if (! is_array($results) || ! array_is_list($results)) {
            throw $this->invalidResponse();
        }

        return array_values(array_filter(
            $results,
            static fn (mixed $result): bool => is_array($result) && ! array_is_list($result),
        ));
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
}
