<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\DataForSeo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\SearchResult;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

/** @internal */
final readonly class DataForSeoResultMapper
{
    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $receipt
     */
    public function result(
        DriverRequest|RecoveryRequest $request,
        array $result,
        array $receipt,
        string $surface,
        string $family,
        string $mode,
        ?string $actualCost,
    ): ResearchResult {
        $observedAt = $this->observedAt($result['datetime'] ?? null);
        $llm = str_ends_with($family, '/llm_responses');
        $normalized = $llm ? $this->normalizeResponses($result) : ($surface === 'google_ai_mode'
            ? $this->normalizeAiMode($result)
            : $this->normalizeScraper($result, $surface));

        if ($normalized['content'] === '') {
            throw DataForSeoDriver::invalidResponse('DataForSEO returned no supported answer content.');
        }

        $completedAt = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $normalized['content'],
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $llm && $normalized['citations']->isEmpty() ? ResultKind::ModelAnswer : $request->profile->resultKind,
                retrievalMethods: $llm && $normalized['citations']->isEmpty() ? $this->modelOnlyRetrievalMethods() : $request->profile->retrievalMethods,
                corpora: $llm && $normalized['citations']->isEmpty() ? $request->profile->corpora->take(0) : $request->profile->corpora,
                observedAt: $observedAt,
                collector: 'dataforseo',
                surface: $llm ? null : $surface,
                context: $llm ? null : [
                    'authentication' => Authentication::Unknown,
                    'personalization' => Personalization::Unknown,
                ],
                observationMode: $llm ? ObservationMode::ApiOutput : ObservationMode::SurfaceSnapshot,
            ),
            citations: $normalized['citations'],
            completedAt: $completedAt,
            model: $this->str($result[$llm ? 'model_name' : 'model'] ?? null),
            usage: $actualCost === null ? null : new Usage(actualCost: $actualCost, currency: 'USD'),
            providerMeta: (object) array_filter([
                'task_id' => $request instanceof DriverRequest ? $this->str($receipt['id'] ?? null) : null,
                'endpoint_family' => $family,
                'mode' => $mode,
                'api_version' => $this->metaString($receipt['_api_version'] ?? null, 64),
                'task_status_code' => $receipt['status_code'] ?? null,
                'task_time' => $this->metaString($receipt['time'] ?? null, 64),
                'reported_cost' => $actualCost,
                'requested_controls' => (object) $this->requestedControls($request),
                'fan_out_queries' => $this->strings($result['fan_out_queries'] ?? null),
                'omitted_item_types' => $normalized['omitted_item_types'],
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
            searchResults: $llm || $surface === 'chatgpt' ? $this->searchResults($result, $llm) : null,
        );
    }

    /** @return Collection<int, RetrievalMethod> */
    private function modelOnlyRetrievalMethods(): Collection
    {
        return collect(['model_only'])->map(static fn (string $method): RetrievalMethod => RetrievalMethod::from($method));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return Collection<int, SearchResult>|null
     */
    private function searchResults(array $result, bool $llm): ?Collection
    {
        if (! is_array($result['search_results'] ?? null) || ! array_is_list($result['search_results'])) {
            return null;
        }
        $results = [];
        foreach ($this->records($result['search_results']) as $item) {
            if (! $llm && ($item['type'] ?? null) !== 'chatgpt_search_result') {
                continue;
            }
            $url = $this->evidenceUrl($item['url'] ?? null);
            if ($url !== null) {
                $results[] = new SearchResult(
                    url: $url,
                    title: $this->str($item['title'] ?? null),
                    snippet: $this->str($item[$llm ? 'snippet' : 'description'] ?? null),
                    publishedAt: $this->optionalDate($item['date'] ?? null),
                );
            }
        }

        return collect($results);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{content: string, citations: Collection<int, Citation>, omitted_item_types: list<string>}
     */
    private function normalizeResponses(array $result): array
    {
        $text = [];
        $annotations = [];
        foreach ($this->records($result['items'] ?? null) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($this->records($item['sections'] ?? null) as $section) {
                if (($section['type'] ?? null) !== 'text') {
                    continue;
                }
                $text[] = $this->str($section['text'] ?? null) ?? '';
                $annotations = [...$annotations, ...$this->records($section['annotations'] ?? null)];
            }
        }

        return [
            'content' => implode("\n\n", $text),
            'citations' => $this->citations($annotations, null, CitationDerivation::ProviderReported),
            'omitted_item_types' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{content: string, citations: Collection<int, Citation>, omitted_item_types: list<string>}
     */
    private function normalizeScraper(array $result, string $surface): array
    {
        $prefix = $surface === 'chatgpt' ? 'chat_gpt' : 'gemini';
        $content = $this->str($result['markdown'] ?? null);
        $supported = ["{$prefix}_text", "{$prefix}_table"];
        $items = $this->records($result['items'] ?? null);
        $omitted = [];
        if ($content === null) {
            $omitted = $this->omittedTypes($items, $supported);
            $content = collect($items)
                ->filter(fn (array $item): bool => in_array($item['type'] ?? null, $supported, true))
                ->map(fn (array $item): ?string => $this->str($item['markdown'] ?? null) ?? $this->str($item['original_text'] ?? null) ?? $this->str($item['text'] ?? null))
                ->filter()
                ->implode("\n\n");
        }

        $sources = $this->records($result['sources'] ?? null);
        foreach ($items as $item) {
            if (in_array($item['type'] ?? null, $supported, true)) {
                $sources = [...$sources, ...$this->records($item['sources'] ?? null)];
            }
        }

        return [
            'content' => $content,
            'citations' => $this->citations($sources, "{$prefix}_source"),
            'omitted_item_types' => $omitted,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{content: string, citations: Collection<int, Citation>, omitted_item_types: list<string>}
     */
    private function normalizeAiMode(array $result): array
    {
        $overview = collect($this->records($result['items'] ?? null))
            ->first(fn (array $item): bool => ($item['type'] ?? null) === 'ai_overview');
        if (! is_array($overview)) {
            /** @var Collection<int, Citation> $citations */
            $citations = collect();

            return ['content' => '', 'citations' => $citations, 'omitted_item_types' => []];
        }

        $elements = $this->records($overview['items'] ?? null);
        $aggregate = $this->str($overview['markdown'] ?? null);
        $content = $aggregate ?? collect($elements)
            ->filter(fn (array $item): bool => ($item['type'] ?? null) === 'ai_overview_element')
            ->map(fn (array $item): ?string => $this->str($item['markdown'] ?? null))
            ->filter()
            ->implode("\n\n");

        $references = $this->records($overview['references'] ?? null);
        foreach ($elements as $element) {
            if (($element['type'] ?? null) === 'ai_overview_element') {
                $references = [...$references, ...$this->records($element['references'] ?? null)];
            }
        }

        return [
            'content' => $content,
            'citations' => $this->citations($references, 'ai_overview_reference'),
            'omitted_item_types' => $aggregate === null ? $this->omittedTypes($elements, ['ai_overview_element']) : [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return Collection<int, Citation>
     */
    private function citations(array $candidates, ?string $expectedType, CitationDerivation $derivation = CitationDerivation::CollectorExtracted): Collection
    {
        $seen = [];
        $citations = [];
        foreach ($candidates as $candidate) {
            if ($expectedType !== null && ($candidate['type'] ?? null) !== $expectedType) {
                continue;
            }

            $url = $this->evidenceUrl($candidate['url'] ?? null);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $publishedAt = $this->optionalDate($candidate['publication_date'] ?? null);
            $citations[] = Citation::make(
                derivation: $derivation,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $this->str($candidate['title'] ?? null),
                    publisher: $this->str($candidate['source_name'] ?? $candidate['source'] ?? null),
                    publishedAt: $publishedAt,
                ),
                excerpt: $this->str($candidate['snippet'] ?? $candidate['text'] ?? null),
                locator: is_int($candidate['start_index'] ?? null) && is_int($candidate['end_index'] ?? null)
                    ? 'characters:'.$candidate['start_index'].'-'.$candidate['end_index'] : null,
            );
        }

        return collect($citations);
    }

    /** @return array<string, int|string|bool> */
    private function requestedControls(DriverRequest|RecoveryRequest $request): array
    {
        return array_filter([
            'location_code' => data_get($request->profile->options, 'location_code'),
            'language_code' => data_get($request->profile->options, 'language_code'),
            'force_web_search' => data_get($request->profile->options, 'force_web_search'),
            'device' => data_get($request->profile->options, 'device'),
            'os' => data_get($request->profile->options, 'os'),
            'model_name' => str_starts_with($request->profile->id, 'dataforseo-llm-') ? ($request->profile->model ?? data_get($request->profile->options, 'model_name')) : null,
            'max_output_tokens' => data_get($request->profile->options, 'max_output_tokens'),
            'web_search_country_iso_code' => data_get($request->profile->options, 'web_search_country_iso_code'),
            'web_search_city' => data_get($request->profile->options, 'web_search_city'),
        ], static fn (mixed $value): bool => is_int($value) || is_string($value) || is_bool($value));
    }

    private function observedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/^(?<seconds>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(?<fraction>\d{1,6}))? \+00:00$/D', $value, $parts) !== 1) {
            throw DataForSeoDriver::invalidResponse('DataForSEO returned an invalid observation timestamp.');
        }

        $normalized = $parts['seconds'].'.'.str_pad($parts['fraction'] ?? '', 6, '0').' +00:00';
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d H:i:s.u P', $normalized);
        if ($parsed === null || $parsed->format('Y-m-d H:i:s.u P') !== $normalized) {
            throw DataForSeoDriver::invalidResponse('DataForSEO returned an invalid observation timestamp.');
        }

        return $parsed->utc();
    }

    private function optionalDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) !== $value || $value === '') {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && ($parts['host'] ?? '') !== '' ? $value : null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $supported
     * @return list<string>
     */
    private function omittedTypes(array $items, array $supported): array
    {
        return array_slice(array_values(array_unique(array_filter(array_map(
            fn (array $item): ?string => ($type = $this->str($item['type'] ?? null)) !== null && ! in_array($type, $supported, true) ? $type : null,
            $items,
        )))), 0, 20);
    }

    /** @return list<array<string, mixed>> */
    private function records(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $records = [];
        foreach ($value as $item) {
            if (is_array($item) && ! array_is_list($item)) {
                /** @var array<string, mixed> $item */
                $records[] = $item;
            }
        }

        return $records;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(
            fn (mixed $item): ?string => $this->metaString($item, 500),
            $value,
        ))), 0, 20);
    }

    private function metaString(mixed $value, int $maxCharacters): ?string
    {
        $value = $this->str($value);

        return $value === null ? null : mb_substr($value, 0, $maxCharacters);
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
