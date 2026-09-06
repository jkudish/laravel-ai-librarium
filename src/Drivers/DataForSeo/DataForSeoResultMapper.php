<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\DataForSeo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
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
        DriverRequest $request,
        array $result,
        array $receipt,
        string $surface,
        string $family,
        string $mode,
        ?string $actualCost,
    ): ResearchResult {
        $observedAt = $this->observedAt($result['datetime'] ?? null);
        $normalized = $surface === 'google_ai_mode'
            ? $this->normalizeAiMode($result)
            : $this->normalizeScraper($result, $surface);

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
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $observedAt,
                collector: 'dataforseo',
                surface: $surface,
                context: [
                    'authentication' => Authentication::Unknown,
                    'personalization' => Personalization::Unknown,
                ],
                observationMode: ObservationMode::SurfaceSnapshot,
            ),
            citations: $normalized['citations'],
            completedAt: $completedAt,
            model: $this->str($result['model'] ?? null),
            usage: $actualCost === null ? null : new Usage(actualCost: $actualCost, currency: 'USD'),
            providerMeta: (object) array_filter([
                'task_id' => $this->str($receipt['id'] ?? null),
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
        );
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
        if ($content === null) {
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
            'omitted_item_types' => $this->omittedTypes($items, $supported),
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
        $content = $this->str($overview['markdown'] ?? null) ?? collect($elements)
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
            'omitted_item_types' => $this->omittedTypes($elements, ['ai_overview_element']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return Collection<int, Citation>
     */
    private function citations(array $candidates, string $expectedType): Collection
    {
        $seen = [];
        $citations = [];
        foreach ($candidates as $candidate) {
            if (($candidate['type'] ?? null) !== $expectedType) {
                continue;
            }

            $url = $this->evidenceUrl($candidate['url'] ?? null);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $publishedAt = $this->optionalDate($candidate['publication_date'] ?? null);
            $citations[] = Citation::make(
                derivation: CitationDerivation::CollectorExtracted,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $this->str($candidate['title'] ?? null),
                    publisher: $this->str($candidate['source_name'] ?? $candidate['source'] ?? null),
                    publishedAt: $publishedAt,
                ),
                excerpt: $this->str($candidate['snippet'] ?? $candidate['text'] ?? null),
            );
        }

        return collect($citations);
    }

    /** @return array<string, int|string|bool> */
    private function requestedControls(DriverRequest $request): array
    {
        return array_filter([
            'location_code' => data_get($request->profile->options, 'location_code'),
            'language_code' => data_get($request->profile->options, 'language_code'),
            'force_web_search' => data_get($request->profile->options, 'force_web_search'),
            'device' => data_get($request->profile->options, 'device'),
            'os' => data_get($request->profile->options, 'os'),
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
