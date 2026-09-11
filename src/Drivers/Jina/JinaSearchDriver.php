<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Jina;

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

/** Executes one inline request against the Jina Search API. */
final readonly class JinaSearchDriver implements Driver
{
    private const string ENDPOINT = 'https://s.jina.ai/';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int REQUEST_TIMEOUT_SECONDS = 120;

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
            throw new DriverException('jina.authentication', 'The Jina Search provider is not configured.');
        }

        $response = $this->send($request, (string) $credential);
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }

        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        $results = $json['data'] ?? [];
        if (! is_array($results) || ! array_is_list($results)) {
            throw $this->invalidResponse();
        }

        return $this->result($request, $results);
    }

    private function validateProfile(DriverRequest $request): void
    {
        $profile = $request->profile;
        if ($profile->id !== 'jina-search'
            || $profile->provider !== 'jina-search'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
            || $profile->options !== []
            || trim($request->prompt) === '') {
            throw new DriverException(
                'jina.invalid_profile',
                'The Jina Search Profile has incompatible identity or provenance semantics.',
                false,
            );
        }
    }

    private function send(DriverRequest $request, string $credential): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withToken($credential)
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->post(self::ENDPOINT, [
                    'q' => $request->prompt,
                    'num' => 10,
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException('jina.connection', 'Jina Search could not be reached.');
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds <= 0) {
            throw new DriverException(
                'jina.deadline_exceeded',
                'The Jina Search request could not start before the research deadline.',
            );
        }

        return $seconds;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('jina.authentication', 'Jina Search rejected the configured credential.'),
            $response->status() === 402 => new DriverException('jina.insufficient_balance', 'The Jina account has insufficient token balance.'),
            $response->status() === 403 => new DriverException('jina.authorization', 'Jina Search denied this request.'),
            in_array($response->status(), [408, 504], true) => $this->deadlineExceeded(),
            $response->status() === 429 => new DriverException('jina.rate_limited', 'Jina Search rate limited this request.'),
            $response->serverError() => new DriverException('jina.unavailable', 'Jina Search is temporarily unavailable.'),
            default => new DriverException('jina.invalid_request', 'Jina Search rejected this request.', false),
        };
    }

    /**
     * @param  list<mixed>  $results
     */
    private function result(DriverRequest $request, array $results): ResearchResult
    {
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $parts = [];

        foreach ($results as $result) {
            if (! is_array($result) || array_is_list($result)) {
                continue;
            }

            $url = $this->evidenceUrl($result['url'] ?? null);
            if ($url === null) {
                continue;
            }

            $reportedTitle = $this->str($result['title'] ?? null);
            $description = $this->str($result['description'] ?? null);
            $content = $this->str($result['content'] ?? null);

            $parts[] = '### ['.$this->markdownInline($reportedTitle ?? 'Untitled').']('.$this->markdownUrl($url).')';
            if ($description !== null) {
                $parts[] = '*'.$this->markdownInline($description).'*';
            }
            if ($content !== null) {
                $parts[] = '';
                $parts[] = $this->markdownText($content);
            }
            $parts[] = '';

            $snippet = $description ?? $content;
            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $reportedTitle,
                ),
                excerpt: $snippet === null ? null : mb_substr($snippet, 0, 200),
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
        ), [(string) $request->profile->credential], $this->invalidResponse());
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
        return new DriverException(
            'jina.deadline_exceeded',
            'Jina Search did not respond before the research deadline.',
        );
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('jina.invalid_response', 'Jina Search returned a malformed response.');
    }
}
