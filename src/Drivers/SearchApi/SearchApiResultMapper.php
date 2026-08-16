<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\SearchApi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use stdClass;

/**
 * Normalizes SearchAPI responses into terminal {@see ResearchResult} values.
 *
 * The mapper only reads documented answer fields. It never retains the raw
 * response, and it maps citations from provider facts alone.
 *
 * @internal
 *
 * @phpstan-type CitationCandidate array{url: mixed, title?: mixed, snippet?: mixed}
 */
final readonly class SearchApiResultMapper
{
    /**
     * Normalize the one-call Google search response into Markdown plus
     * provider-reported citations.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{content: string, citations: Collection<int, Citation>}
     */
    public function normalizeGoogle(array $data): array
    {
        /** @var list<CitationCandidate> $candidates */
        $candidates = [];

        $organicResults = $this->records(data_get($data, 'organic_results'));
        $organic = $this->renderOrganic($organicResults, $candidates);

        $sections = array_values(array_filter([
            $this->renderAiOverview(data_get($data, 'ai_overview'), $candidates),
            $this->renderLinkedSection('Top Stories', data_get($data, 'top_stories'), $candidates,
                fn (array $item): string => $this->joinMeta([$this->str($item['source'] ?? null), $this->str($item['date'] ?? null)])),
            $this->renderLinkedSection('Discussions & Forums', data_get($data, 'discussions_and_forums'), $candidates,
                fn (array $item): string => $this->joinMeta([$this->str($item['source'] ?? null), $this->str($item['date'] ?? null), $this->str($item['posts'] ?? null)])),
            $this->renderLinkedSection('Inline Videos', data_get($data, 'inline_videos'), $candidates,
                fn (array $item): string => $this->joinMeta([$this->str($item['source'] ?? null), $this->str($item['channel'] ?? null) ?? $this->str($item['author'] ?? null), $this->str($item['date'] ?? null), $this->str($item['length'] ?? null)])),
            $this->renderKnowledgeGraph(data_get($data, 'knowledge_graph'), $candidates),
        ], static fn (string $section): bool => $section !== ''));

        if ($sections === []) {
            $content = $organic;
        } elseif ($organicResults === []) {
            $content = implode("\n\n", $sections);
        } else {
            $content = $organic."\n".implode("\n\n", $sections);
        }

        return [
            'content' => $content,
            'citations' => $this->citations($candidates, CitationDerivation::ProviderReported),
        ];
    }

    /**
     * Normalize a SearchAPI consumer-surface answer. Markdown is preferred when
     * complete; typed text blocks provide a deterministic fallback. Organic or
     * local SERP sections are never consulted.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{content: string, citations: Collection<int, Citation>, model: ?string}
     */
    public function normalizeSurface(array $data): array
    {
        $markdown = $this->usableMarkdown(data_get($data, 'markdown'));
        $content = $markdown ?? $this->renderSurfaceBlocks(data_get($data, 'text_blocks'));

        return [
            'content' => $content,
            'citations' => $this->citations($this->referenceCandidates(data_get($data, 'reference_links')), CitationDerivation::CollectorExtracted),
            'model' => $this->str(data_get($data, 'response_metadata.model')),
        ];
    }

    /**
     * @param  Collection<int, Citation>  $citations
     */
    public function result(
        DriverRequest $request,
        string $content,
        Collection $citations,
        ?string $surface,
        ?string $model,
        ?stdClass $providerMeta,
    ): ResearchResult {
        $now = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $content,
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
                collector: $surface === null ? null : 'searchapi',
                surface: $surface,
            ),
            citations: $citations,
            completedAt: $now,
            model: $model,
            providerMeta: $providerMeta,
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $results
     * @param  list<CitationCandidate>  $candidates
     */
    private function renderOrganic(array $results, array &$candidates): string
    {
        if ($results === []) {
            return 'No results found.';
        }

        $parts = [];
        foreach ($results as $result) {
            $link = $this->evidenceUrl(data_get($result, 'link'));
            if ($link === null) {
                continue;
            }

            $title = $this->str(data_get($result, 'title')) ?? 'Untitled';
            $parts[] = "### [{$title}]({$link})";
            $snippet = $this->str(data_get($result, 'snippet'));
            if ($snippet !== null) {
                $parts[] = $snippet;
            }
            $parts[] = '';
            $candidates[] = ['url' => $link, 'title' => data_get($result, 'title'), 'snippet' => data_get($result, 'snippet')];
        }

        return $parts === [] ? 'No results found.' : implode("\n", $parts);
    }

    /** @param list<CitationCandidate> $candidates */
    private function renderAiOverview(mixed $overview, array &$candidates): string
    {
        $record = $this->record($overview);
        if ($record === null) {
            return '';
        }

        $text = $this->usableMarkdown(data_get($record, 'markdown')) ?? $this->renderSurfaceBlocks(data_get($record, 'text_blocks'));
        if ($text === '') {
            return '';
        }

        foreach ($this->records(data_get($record, 'reference_links')) as $link) {
            $candidates[] = ['url' => data_get($link, 'link'), 'title' => data_get($link, 'title'), 'snippet' => data_get($link, 'snippet')];
        }

        return "## AI Overview\n\n{$text}";
    }

    /**
     * @param  list<CitationCandidate>  $candidates
     * @param  callable(array<array-key, mixed>): string  $describe
     */
    private function renderLinkedSection(string $heading, mixed $items, array &$candidates, callable $describe): string
    {
        $lines = [];
        foreach ($this->records($items) as $item) {
            $url = $this->evidenceUrl(data_get($item, 'link'));
            if ($url === null) {
                continue;
            }
            $title = $this->str(data_get($item, 'title')) ?? $this->str(data_get($item, 'name')) ?? 'Untitled';
            $suffix = $describe($item);
            $lines[] = "- [{$title}]({$url})".($suffix === '' ? '' : " — {$suffix}");
            $candidates[] = ['url' => $url, 'title' => data_get($item, 'title') ?? data_get($item, 'name'), 'snippet' => data_get($item, 'snippet')];
        }

        return $lines === [] ? '' : "## {$heading}\n\n".implode("\n", $lines);
    }

    /** @param list<CitationCandidate> $candidates */
    private function renderKnowledgeGraph(mixed $knowledgeGraph, array &$candidates): string
    {
        $record = $this->record($knowledgeGraph);
        if ($record === null) {
            return '';
        }

        $sources = [...$this->records(data_get($record, 'source')), ...$this->records(data_get($record, 'sources'))];

        return $this->renderLinkedSection('Knowledge Graph Sources', $sources, $candidates, static fn (): string => '');
    }

    /**
     * @param  list<CitationCandidate>  $candidates
     * @return Collection<int, Citation>
     */
    private function citations(array $candidates, CitationDerivation $derivation): Collection
    {
        $seen = [];
        $citations = [];
        foreach ($candidates as $candidate) {
            $url = $this->evidenceUrl($candidate['url'] ?? null);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $citations[] = Citation::make(
                derivation: $derivation,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $this->str($candidate['title'] ?? null),
                ),
                excerpt: $this->str($candidate['snippet'] ?? null),
            );
        }

        return collect($citations);
    }

    /**
     * @return list<CitationCandidate>
     */
    private function referenceCandidates(mixed $links): array
    {
        $candidates = [];
        foreach ($this->records($links) as $link) {
            $candidates[] = [
                'url' => data_get($link, 'url') ?? data_get($link, 'link') ?? data_get($link, 'href'),
                'title' => data_get($link, 'title') ?? data_get($link, 'name'),
                'snippet' => data_get($link, 'snippet') ?? data_get($link, 'description'),
            ];
        }

        return $candidates;
    }

    private function usableMarkdown(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $markdown = trim($value);
        if ($markdown === '' || $this->hasControlCharacter($markdown) || ! $this->hasCompleteMarkdownLinks($markdown)) {
            return null;
        }

        return $markdown;
    }

    private function hasControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1;
    }

    private function hasCompleteMarkdownLinks(string $markdown): bool
    {
        $length = strlen($markdown);
        for ($cursor = 0; $cursor < $length; $cursor++) {
            if ($markdown[$cursor] !== '[' || ($cursor > 0 && $markdown[$cursor - 1] === '\\')) {
                continue;
            }
            $labelEnd = strpos($markdown, '](', $cursor + 1);
            if ($labelEnd === false) {
                continue;
            }

            $depth = 1;
            $closed = false;
            for ($index = $labelEnd + 2; $index < $length; $index++) {
                if ($markdown[$index] === '\\') {
                    $index++;
                } elseif ($markdown[$index] === '(') {
                    $depth++;
                } elseif ($markdown[$index] === ')' && --$depth === 0) {
                    $closed = true;
                    $cursor = $index;
                    break;
                }
            }
            if (! $closed) {
                return false;
            }
        }

        return true;
    }

    private function renderSurfaceBlocks(mixed $blocks): string
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return '';
        }

        $rendered = [];
        foreach ($blocks as $block) {
            $rendered = [...$rendered, ...$this->renderSurfaceBlock($block, 0)];
        }

        return trim(implode("\n\n", array_filter($rendered, static fn (string $line): bool => $line !== '')));
    }

    /** @return list<string> */
    private function renderSurfaceBlock(mixed $block, int $depth): array
    {
        $record = $this->record($block);
        $type = $record === null ? null : $this->str(data_get($record, 'type'));
        if ($record === null || $type === null) {
            return [];
        }

        $body = $this->str(data_get($record, 'text')) ?? $this->str(data_get($record, 'content')) ?? $this->str(data_get($record, 'answer'));
        $children = $this->childBlocks($record);

        return match ($type) {
            'text', 'paragraph' => $this->appendChildren($body === null ? [] : [$body], $children, $depth),
            'heading' => $this->appendChildren($body === null ? [] : [str_repeat('#', $this->headingLevel(data_get($record, 'level')))." {$body}"], $children, $depth),
            'header' => $this->appendChildren($body === null ? [] : ["## {$body}"], $children, $depth),
            'quote' => $this->appendChildren($body === null ? [] : ["> {$body}"], $children, $depth),
            'code', 'code_block', 'code_blocks' => $this->appendChildren($this->renderCode($record, $body), $children, $depth),
            'list', 'unordered_list', 'ordered_list' => $this->renderListItems($children, $depth, $type === 'ordered_list'),
            'list_item', 'item' => $this->renderListItem($record, $depth, null),
            default => [],
        };
    }

    /**
     * @param  list<string>  $rendered
     * @param  list<mixed>  $children
     * @return list<string>
     */
    private function appendChildren(array $rendered, array $children, int $depth): array
    {
        foreach ($children as $child) {
            $rendered = [...$rendered, ...$this->renderSurfaceBlock($child, $depth + 1)];
        }

        return $rendered;
    }

    /**
     * @param  list<mixed>  $children
     * @return list<string>
     */
    private function renderListItems(array $children, int $depth, bool $ordered): array
    {
        $items = [];
        $index = 1;
        foreach ($children as $child) {
            $items = [...$items, ...$this->renderListItem($child, $depth, $ordered ? $index++ : null)];
        }

        return $items;
    }

    /** @return list<string> */
    private function renderListItem(mixed $item, int $depth, ?int $index): array
    {
        $record = $this->record($item);
        if ($record === null) {
            return [];
        }

        $body = $this->str(data_get($record, 'text')) ?? $this->str(data_get($record, 'content')) ?? $this->str(data_get($record, 'answer'));
        $prefix = str_repeat('  ', $depth).($index === null ? '-' : "{$index}.").' ';
        $rendered = $body === null ? [] : ["{$prefix}{$body}"];

        return $this->appendChildren($rendered, $this->childBlocks($record), $depth + 1);
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return list<string>
     */
    private function renderCode(array $record, ?string $body): array
    {
        $code = $this->str(data_get($record, 'code')) ?? $body;
        if ($code === null) {
            return [];
        }

        $language = $this->str(data_get($record, 'language')) ?? '';

        return ["```{$language}\n{$code}\n```"];
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return list<mixed>
     */
    private function childBlocks(array $record): array
    {
        $children = data_get($record, 'children') ?? data_get($record, 'blocks') ?? data_get($record, 'items');

        return is_array($children) && array_is_list($children) ? $children : [];
    }

    private function headingLevel(mixed $level): int
    {
        return is_int($level) ? max(1, min(6, $level)) : 2;
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

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || $this->isNavigationHost($host)) {
            return null;
        }

        return $value;
    }

    private function isNavigationHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'searchapi.io'
            || str_ends_with($host, '.searchapi.io')
            || $host === 'googleusercontent.com'
            || str_ends_with($host, '.googleusercontent.com')
            || preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1;
    }

    /** @param list<?string> $values */
    private function joinMeta(array $values): string
    {
        return implode(' · ', array_filter($values, static fn (?string $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function records(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
        }

        $record = $this->record($value);

        return $record === null ? [] : [$record];
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function record(mixed $value): ?array
    {
        return is_array($value) && ! array_is_list($value) ? $value : null;
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
