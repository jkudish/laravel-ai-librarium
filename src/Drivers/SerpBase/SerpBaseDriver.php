<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\SerpBase;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

/** Runs one synchronous SerpBase Google Search or Google News request. */
final readonly class SerpBaseDriver implements DefinesSpendingBound, Driver
{
    private const string BASE_URL = 'https://api.serpbase.dev';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int RICH_MAX_DEPTH = 4;

    private const int RICH_MAX_ENTRIES = 20;

    private const int RICH_MAX_STRING_BYTES = 2_000;

    private const int RICH_MAX_MODULE_BYTES = 8_000;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'hl' => ['bail', 'sometimes', 'filled', 'string'],
            'gl' => ['bail', 'sometimes', 'filled', 'string'],
            'page' => ['bail', 'sometimes', 'integer:strict', 'min:1'],
            'device' => ['bail', 'sometimes', 'string', 'in:default,pc,mobile'],
            'include_rich_results' => ['bail', 'sometimes', 'boolean:strict'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $kind = $this->preflight($request);
        $response = $this->send($request, $kind);

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded('SerpBase did not respond before the research deadline.');
        }

        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }

        if (strlen($response->body()) > 2_000_000) {
            throw $this->invalidResponse();
        }
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        $status = $this->validateEnvelope($json, $kind);
        if ($status !== 0) {
            throw $this->businessFailure($status);
        }

        return $this->result($request, $json, $kind);
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        $profile = $request->profile;
        $model = match ($profile->id) {
            'serpbase-search' => ($profile->options['include_rich_results'] ?? false) === false ? 'search' : null,
            'serpbase-news' => 'news',
            default => null,
        };

        if ($model === null) {
            return SpendingBound::unknown();
        }

        return SpendingBound::billableQuantities(
            new ModelIdentity('serpbase', $model),
            new PricingUsage(['credits' => 1]),
        );
    }

    private function preflight(DriverRequest $request): string
    {
        $profile = $request->profile;
        $kind = match ($profile->id) {
            'serpbase-search' => 'search',
            'serpbase-news' => 'news',
            default => null,
        };

        if ($kind === null
            || $profile->provider !== 'serpbase'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [$kind === 'news' ? Corpus::News : Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]) {
            throw new DriverException(
                'serpbase.invalid_profile',
                'The SerpBase Profile has incompatible identity or provenance semantics.',
                false,
            );
        }

        $allowed = $kind === 'search'
            ? ['hl', 'gl', 'page', 'device', 'include_rich_results']
            : ['hl', 'gl', 'page'];
        $rules = ['query' => ['bail', 'required', 'string']];
        foreach (self::profileOptionRules() as $key => $rule) {
            if (in_array($key, $allowed, true)) {
                $rules['options.'.$key] = $rule;
            }
        }

        try {
            Validator::make(
                ['query' => $request->prompt, 'options' => $profile->options],
                ['options' => ['array:'.implode(',', $allowed)], ...$rules],
            )->validate();
        } catch (ValidationException) {
            throw new DriverException('serpbase.invalid_request', 'The SerpBase query or options are invalid.', false);
        }

        if (trim($request->prompt) === '') {
            throw new DriverException('serpbase.invalid_request', 'The SerpBase query or options are invalid.', false);
        }

        if (blank($profile->credential)) {
            throw new DriverException('serpbase.authentication', 'The SerpBase provider is not configured.');
        }

        $this->remainingSeconds($request);

        return $kind;
    }

    private function send(DriverRequest $request, string $kind): Response
    {
        $remaining = $this->remainingSeconds($request);
        $payload = ['q' => $request->prompt];
        foreach (['hl', 'gl', 'page', 'device'] as $option) {
            if (array_key_exists($option, $request->profile->options)) {
                $payload[$option] = $request->profile->options[$option];
            }
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => (string) $request->profile->credential])
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout($remaining)
                ->post(self::BASE_URL.'/google/'.$kind, $payload);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded('SerpBase did not respond before the research deadline.');
            }

            throw new DriverException('serpbase.connection', 'SerpBase could not be reached.');
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    /** @param array<array-key, mixed> $json */
    private function validateEnvelope(array $json, string $kind): int
    {
        $status = data_get($json, 'status');
        if (! is_int($status)) {
            throw $this->invalidResponse();
        }

        if ($status !== 0) {
            return $status;
        }

        $requestId = data_get($json, 'request_id');
        $elapsed = data_get($json, 'elapsed_ms');
        $credits = data_get($json, 'credits_charged');
        $searchType = data_get($json, 'search_type');
        $query = data_get($json, 'query');
        $page = data_get($json, 'page');

        if (! is_string($requestId) || trim($requestId) === '' || strlen($requestId) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $requestId) === 1
            || (! is_int($elapsed) && ! is_float($elapsed)) || ! is_finite((float) $elapsed) || $elapsed < 0
            || (! is_int($credits) && ! is_float($credits)) || ! is_finite((float) $credits) || $credits < 0
            || $searchType !== $kind
            || ! is_string($query)
            || ! is_int($page) || $page < 1) {
            throw $this->invalidResponse();
        }

        return $status;
    }

    /** @param array<array-key, mixed> $json */
    private function result(DriverRequest $request, array $json, string $kind): ResearchResult
    {
        $credential = (string) $request->profile->credential;
        if ($this->containsCredential($json, $credential)) {
            throw $this->invalidResponse();
        }

        $resultKey = $kind === 'search' ? 'organic' : 'news';
        $items = array_key_exists($resultKey, $json)
            ? $this->records($json[$resultKey], false, true)
            : [];
        if (count($items) > 100) {
            throw $this->invalidResponse();
        }
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $seen = [];
        $parts = $this->renderPrimary($items, $kind, $citations, $seen);
        $resultCount = $citations->count();

        if ($kind === 'search' && data_get($request->profile->options, 'include_rich_results', false) === true) {
            $parts = [...$parts, ...$this->renderRich(
                $json,
                $citations,
                $seen,
            )];
        }

        $completedAt = CarbonImmutable::now();

        return TerminalRedaction::guard(ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : implode("\n\n", $parts),
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
            providerMeta: (object) [
                'result_count' => $resultCount,
                'credits_charged' => data_get($json, 'credits_charged'),
            ],
        ), [(string) $request->profile->credential], $this->invalidResponse());
    }

    /**
     * @param  list<array<array-key, mixed>>  $items
     * @param  Collection<int, Citation>  $citations
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function renderPrimary(array $items, string $kind, Collection $citations, array &$seen): array
    {
        $parts = [];
        foreach ($items as $item) {
            $rank = data_get($item, 'rank');
            $title = $this->str(data_get($item, 'title'));
            $url = $this->evidenceUrl(data_get($item, 'link'));
            if (! is_int($rank) || $rank < 1 || $title === null || $url === null) {
                throw $this->invalidResponse();
            }
            if (isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $snippet = $this->str(data_get($item, 'snippet'));
            $lines = ['### '.$rank.'. ['.$this->markdownInline($title).']('.$this->markdownUrl($url).')'];

            if ($kind === 'news') {
                $metadata = array_values(array_filter([
                    $this->str(data_get($item, 'source')),
                    $this->str(data_get($item, 'published_at')) ?? $this->str(data_get($item, 'time')),
                ]));
                if ($metadata !== []) {
                    $lines[] = '*'.$this->markdownText(implode(' · ', $metadata)).'*';
                }
            }
            if ($snippet !== null) {
                $lines[] = $this->markdownText($snippet);
            }
            $parts[] = implode("\n", $lines);
            $citations->push($this->citation(
                $url,
                $title,
                $snippet,
                $kind === 'news' ? $this->str(data_get($item, 'source')) : null,
                $kind === 'news' ? SourceKind::NewsArticle : SourceKind::WebPage,
            ));
        }

        return $parts;
    }

    /**
     * PAA uses the provider's published example fields. Other rich nested
     * schemas are unpublished, so they remain uninterpreted bounded JSON-like
     * text and never create citations.
     *
     * @param  array<array-key, mixed>  $json
     * @param  Collection<int, Citation>  $citations
     * @param  array<string, true>  $seen
     * @return list<string>
     */
    private function renderRich(array $json, Collection $citations, array &$seen): array
    {
        $sections = [];
        $featured = data_get($json, 'featured_snippet');
        if (is_array($featured) && ! array_is_list($featured)) {
            $sections[] = $this->renderOpaqueRich('Featured Snippet', $this->record($featured));
        }

        $paa = data_get($json, 'people_also_ask');
        if (is_array($paa) && array_is_list($paa)) {
            $lines = [];
            foreach (array_slice($this->records($paa, false), 0, self::RICH_MAX_ENTRIES) as $item) {
                $question = $this->str(data_get($item, 'question'));
                $snippet = $this->str(data_get($item, 'snippet'));
                $displayedLink = $this->str(data_get($item, 'displayed_link'));
                $url = $this->evidenceUrl(data_get($item, 'link'));
                if ($question === null) {
                    continue;
                }

                $label = $this->markdownInline($question);
                $lines[] = $url === null
                    ? '- **'.$label.'**'
                    : '- **['.$label.']('.$this->markdownUrl($url).')**';
                if ($snippet !== null) {
                    $lines[] = '  '.$this->markdownText($snippet);
                }
                if ($displayedLink !== null) {
                    $lines[] = '  *'.$this->markdownText($displayedLink).'*';
                }
                if ($url !== null && ! isset($seen[$url])) {
                    $seen[$url] = true;
                    $citations->push($this->citation($url, $question, $snippet));
                }
            }
            if ($lines !== []) {
                $sections[] = "## People Also Ask\n\n".implode("\n", $lines);
            }
        }

        $stories = data_get($json, 'top_stories');
        if (is_array($stories) && array_is_list($stories)) {
            $sections[] = $this->renderOpaqueRich('Top Stories', $this->records($stories, false));
        }

        $knowledge = data_get($json, 'knowledge_graph');
        if (is_array($knowledge) && ! array_is_list($knowledge)) {
            $sections[] = $this->renderOpaqueRich('Knowledge Graph', $this->record($knowledge));
        }

        $related = data_get($json, 'related_searches');
        if (is_array($related) && array_is_list($related)) {
            $suggestions = [];
            foreach (array_slice($related, 0, self::RICH_MAX_ENTRIES) as $suggestion) {
                $suggestion = $this->str($suggestion);
                if ($suggestion !== null) {
                    $suggestions[$suggestion] = $suggestion;
                }
            }
            if ($suggestions !== []) {
                $sections[] = "## Related Searches\n\n".implode("\n", array_map(
                    fn (string $suggestion): string => '- '.$this->markdownText($suggestion),
                    array_values($suggestions),
                ));
            }
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function renderOpaqueRich(string $heading, array $value): string
    {
        $projected = $this->opaqueValue($value, 0);
        $encoded = json_encode($projected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            throw $this->invalidResponse();
        }

        $prefix = "## {$heading}\n\n```json\n";
        $suffix = "\n```";
        $marker = "\n[truncated]";
        $encoded = str_replace('`', '\u0060', $encoded);
        if (strlen($prefix.$encoded.$suffix) > self::RICH_MAX_MODULE_BYTES) {
            $available = self::RICH_MAX_MODULE_BYTES - strlen($prefix.$marker.$suffix);
            $encoded = mb_strcut($encoded, 0, $available).$marker;
        }

        return $prefix.$encoded.$suffix;
    }

    /**
     * @return array<array-key, mixed>|bool|float|int|string|null
     */
    private function opaqueValue(mixed $value, int $depth): array|bool|float|int|string|null
    {
        if ($depth > self::RICH_MAX_DEPTH) {
            return '[truncated]';
        }

        if (is_array($value)) {
            $projected = [];
            foreach (array_slice($value, 0, self::RICH_MAX_ENTRIES, true) as $key => $nested) {
                if (is_string($key) && ($this->sensitiveKey($key)
                    || in_array(strtolower($key), ['__proto__', 'constructor', 'prototype'], true)
                    || preg_match('/(?:image|thumbnail|icon|photo)/i', $key) === 1)) {
                    continue;
                }
                $boundedKey = is_string($key)
                    ? mb_strcut($key, 0, self::RICH_MAX_STRING_BYTES)
                    : $key;
                $projected[$boundedKey] = $this->opaqueValue($nested, $depth + 1);
            }
            if (count($value) > self::RICH_MAX_ENTRIES) {
                $projected['_truncated'] = true;
            }

            return $projected;
        }

        if (is_string($value)) {
            $urlParts = parse_url($value);
            if (is_array($urlParts) && array_key_exists('scheme', $urlParts) && $this->evidenceUrl($value) === null) {
                return '[omitted unsafe URL]';
            }

            return mb_strcut($value, 0, self::RICH_MAX_STRING_BYTES);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return null;
    }

    private function sensitiveKey(string $key): bool
    {
        return preg_match('/(?:key|token|secret|credential|password|passwd|request.?id|auth|bearer|session|signature|cookie|(?:^|[-_])sig(?:$|[-_]))/i', $key) === 1;
    }

    private function containsCredential(mixed $value, string $credential): bool
    {
        if ($credential === '') {
            return false;
        }
        if (is_string($value)) {
            return str_contains($value, $credential);
        }
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $nested) {
            if ((is_string($key) && str_contains($key, $credential))
                || $this->containsCredential($nested, $credential)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function records(mixed $value, bool $absentIsEmpty = true, bool $strictItems = false): array
    {
        if ($value === null && $absentIsEmpty) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalidResponse();
        }

        if ($strictItems && array_filter(
            $value,
            static fn (mixed $item): bool => ! is_array($item) || array_is_list($item),
        ) !== []) {
            throw $this->invalidResponse();
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_array($item) && ! array_is_list($item),
        ));
    }

    /** @return array<array-key, mixed> */
    private function record(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    private function citation(
        string $url,
        ?string $title,
        ?string $excerpt,
        ?string $publisher = null,
        SourceKind $kind = SourceKind::WebPage,
    ): Citation {
        return Citation::make(
            derivation: CitationDerivation::ProviderReported,
            source: new Source(
                kind: $kind,
                url: $url,
                title: $title,
                publisher: $publisher,
            ),
            excerpt: $excerpt,
        );
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('serpbase.authentication', 'SerpBase rejected the configured credential.'),
            $response->status() === 403 => new DriverException('serpbase.authorization', 'SerpBase denied this request.'),
            $response->status() === 402 => new DriverException('serpbase.quota_exceeded', 'The SerpBase account has insufficient credits.'),
            in_array($response->status(), [408, 504], true) => $this->deadlineExceeded('SerpBase timed out while processing this request.'),
            $response->status() === 429 => new DriverException('serpbase.rate_limited', 'SerpBase rate limited this request.'),
            $response->serverError() => new DriverException('serpbase.unavailable', 'SerpBase is temporarily unavailable.'),
            default => new DriverException('serpbase.invalid_request', 'SerpBase rejected this request.', false),
        };
    }

    private function businessFailure(int $status): DriverException
    {
        return match ($status) {
            1000 => new DriverException('serpbase.invalid_request', 'SerpBase rejected this request.', false),
            1001 => new DriverException('serpbase.authentication', 'SerpBase rejected the configured credential.'),
            1004 => new DriverException('serpbase.not_found', 'SerpBase could not find the requested resource.', false),
            1020 => new DriverException('serpbase.quota_exceeded', 'The SerpBase account has insufficient credits.'),
            1029 => new DriverException('serpbase.rate_limited', 'SerpBase rate limited this request.'),
            1504 => $this->deadlineExceeded('SerpBase timed out while processing this request.'),
            1500, 1502, 1503 => new DriverException('serpbase.unavailable', 'SerpBase is temporarily unavailable.'),
            default => new DriverException('serpbase.provider', 'SerpBase reported an error for this request.'),
        };
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds <= 0) {
            throw $this->deadlineExceeded('The SerpBase request could not start before the research deadline.');
        }

        return $seconds;
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

        return $trimmed === '' ? null : mb_strcut($trimmed, 0, self::RICH_MAX_STRING_BYTES);
    }

    private function deadlineExceeded(string $message): DriverException
    {
        return new DriverException('serpbase.deadline_exceeded', $message);
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('serpbase.invalid_response', 'SerpBase returned a malformed response.');
    }
}
