<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Cloro;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Authentication;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use stdClass;

/**
 * Maps Cloro results to terminal values. The answer and its `sources` and
 * `citationPills` become the result; entities, fan-out queries, shopping
 * cards, ads and every other extra stay in the redacted evidence only.
 *
 * @internal
 */
final readonly class CloroResultMapper
{
    private const int MAX_STRING_BYTES = 2_000;

    /** @param array<array-key, mixed> $data */
    public function surface(DriverRequest|RecoveryRequest $request, array $data, string $sku, string $surface, stdClass $meta): ResearchResult
    {
        if ($sku === 'google-ai-overview') {
            $overview = $data['aioverview'] ?? null;
            if (! is_array($overview) || array_is_list($overview)) {
                throw new DriverException('cloro.no_ai_overview', 'Google showed no AI Overview for this search.', false);
            }
            $data = $overview;
        }
        $content = $this->str($data['markdown'] ?? null, null) ?? $this->str($data['text'] ?? null, null);
        if ($content === null) {
            throw CloroDriver::invalidResponse('Cloro returned no answer for this surface.');
        }

        $citations = $this->citations([...$this->records($data['sources'] ?? null), ...$this->records($data['citationPills'] ?? null)], CitationDerivation::CollectorExtracted);
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
                collector: 'cloro',
                surface: $surface,
                // Cloro runs every surface in a logged-out browser session.
                context: ['authentication' => Authentication::Anonymous, 'personalization' => Personalization::Unknown],
                observationMode: ObservationMode::SurfaceSnapshot,
            ),
            citations: $citations,
            completedAt: $now,
            model: $this->model($data['model'] ?? null),
            providerMeta: $meta,
        );
    }

    /** @param array<array-key, mixed> $data */
    public function search(DriverRequest|RecoveryRequest $request, array $data, bool $news, stdClass $meta): ResearchResult
    {
        $items = $this->records($data[$news ? 'newsResults' : 'organicResults'] ?? null);
        $parts = [];
        $candidates = [];
        foreach ($items as $index => $item) {
            $url = EvidenceUrl::sanitize($item['link'] ?? null);
            $title = $this->str($item['title'] ?? null);
            if ($url === null || $title === null) {
                continue;
            }
            $position = is_int($item['position'] ?? null) ? $item['position'] : $index + 1;
            $snippet = $this->str($item['snippet'] ?? null);
            $publisher = $news ? $this->str($item['source'] ?? null) : null;
            $lines = ['### '.$position.'. ['.$this->markdown(str_replace(["\r", "\n"], ' ', $title)).']('.str_replace(['\\', '(', ')'], ['%5C', '%28', '%29'], $url).')'];
            $byline = array_values(array_filter([$publisher, $news ? $this->str($item['date'] ?? null) : null]));
            if ($byline !== []) {
                $lines[] = '*'.$this->markdown(implode(' · ', $byline)).'*';
            }
            if ($snippet !== null) {
                $lines[] = $this->markdown($snippet);
            }
            $parts[$url] ??= implode("\n", $lines);
            $candidates[] = ['url' => $url, 'label' => $title, 'description' => $snippet, 'publisher' => $publisher];
        }
        $now = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : implode("\n\n", $parts),
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: $this->citations($candidates, CitationDerivation::ProviderReported, $news ? SourceKind::NewsArticle : SourceKind::WebPage),
            completedAt: $now,
            providerMeta: $meta,
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $candidates
     * @return Collection<int, Citation>
     */
    private function citations(array $candidates, CitationDerivation $derivation, SourceKind $kind = SourceKind::WebPage): Collection
    {
        $seen = [];
        $citations = [];
        foreach ($candidates as $candidate) {
            $url = EvidenceUrl::sanitize($candidate['url'] ?? null);
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $citations[] = Citation::make(
                derivation: $derivation,
                source: new Source(
                    kind: $kind,
                    url: $url,
                    title: $this->str($candidate['label'] ?? null),
                    publisher: $this->str($candidate['publisher'] ?? null),
                ),
                excerpt: $this->str($candidate['description'] ?? null),
            );
        }

        return collect($citations);
    }

    private function model(mixed $value): ?string
    {
        $model = $this->str($value);

        return $model !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $model) === 1 ? $model : null;
    }

    /** @return list<array<array-key, mixed>> */
    private function records(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item) && ! array_is_list($item)));
    }

    private function str(mixed $value, ?int $maxBytes = self::MAX_STRING_BYTES): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $maxBytes === null ? trim($value) : mb_strcut(trim($value), 0, $maxBytes);
    }

    private function markdown(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|', '~', '=', '^', '$'],
            ['\\\\', '\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\(', '\\)', '\\#', '\\+', '\\-', '\\.', '\\!', '\\|', '\\~', '\\=', '\\^', '\\$'],
            $escaped,
        );
    }
}
