<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\You;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;

/** Maps synchronous and completed background You.com Research output. */
final readonly class YouResearchResultMapper
{
    /** @param array<array-key, mixed> $json */
    public function map(DriverRequest|RecoveryRequest $request, array $json, bool $background): ResearchResult
    {
        $result = $background ? data_get($json, 'result') : $json;
        $output = data_get($result, 'output');
        $warnings = data_get($result, 'warnings', []);
        if (! is_array($output) || array_is_list($output)
            || ! is_array($warnings) || ! array_is_list($warnings)
            || array_any($warnings, static fn (mixed $warning): bool => ! is_string($warning))) {
            throw $this->invalidResponse();
        }

        $content = data_get($output, 'content');
        $contentType = data_get($output, 'content_type');
        $sourceValues = data_get($output, 'sources', []);
        if (! is_string($content) || trim($content) === '' || $contentType !== 'text'
            || ! is_array($sourceValues) || ! array_is_list($sourceValues)) {
            throw $this->invalidResponse();
        }

        [$citations, $sources] = $this->sources($sourceValues);
        $completedAt = CarbonImmutable::now();
        $metadata = [
            'research_effort' => 'standard',
            'execution' => $background ? 'background' : 'inline',
            ...($background ? ['resumability' => 'durable_provider_task'] : []),
            'sources' => $sources,
            'warnings' => $warnings,
        ];

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
                observedAt: $completedAt,
            ),
            citations: $citations,
            completedAt: $completedAt,
            providerMeta: (object) ['you-com:research' => $metadata],
        );
    }

    /**
     * @param  list<mixed>  $values
     * @return array{Collection<int, Citation>, list<array{url: string, title?: string, snippets: list<string>}>}
     */
    private function sources(array $values): array
    {
        $citations = [];
        $sources = [];

        foreach ($values as $index => $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw $this->invalidResponse();
            }

            $url = $this->evidenceUrl(data_get($value, 'url'));
            $title = data_get($value, 'title');
            $snippets = data_get($value, 'snippets', []);
            if ($url === null
                || ($title !== null && (! is_string($title) || trim($title) === ''))
                || ! is_array($snippets) || ! array_is_list($snippets)
                || array_any($snippets, static fn (mixed $snippet): bool => ! is_string($snippet))) {
                throw $this->invalidResponse();
            }

            /** @var list<string> $nonblankSnippets */
            $nonblankSnippets = array_values(array_filter($snippets, static fn (mixed $snippet): bool => is_string($snippet) && trim($snippet) !== ''));
            $citations[] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(kind: SourceKind::WebPage, url: $url, title: is_string($title) ? $title : null),
                excerpt: isset($nonblankSnippets[0]) ? mb_substr($nonblankSnippets[0], 0, 200) : null,
                locator: '[['.($index + 1).']]',
            );
            $sources[] = array_filter([
                'url' => $url,
                'title' => $title,
                'snippets' => $nonblankSnippets,
            ], static fn (mixed $item): bool => $item !== null);
        }

        return [collect($citations), $sources];
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

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'you_research.invalid_response',
            'You.com Research returned an invalid research result.',
            false,
        );
    }
}
