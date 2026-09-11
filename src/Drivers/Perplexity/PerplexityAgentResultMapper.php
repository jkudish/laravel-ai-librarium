<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Perplexity;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

/** @internal */
final readonly class PerplexityAgentResultMapper
{
    /** @var list<string> */
    private const array IGNORED_OUTPUT_TYPES = [
        'fetch_url_results',
        'finance_results',
        'people_search_results',
        'function_call',
        'sandbox_results',
        'mcp_list_tools',
        'mcp_call',
        'tool_search',
    ];

    /** @var array<string, SourceKind> */
    private const array SOURCE_KINDS = [
        'web' => SourceKind::WebPage,
        'news' => SourceKind::NewsArticle,
        'x' => SourceKind::XPost,
        'file' => SourceKind::File,
        'place' => SourceKind::Place,
        'video' => SourceKind::Video,
        'forum' => SourceKind::ForumPost,
    ];

    /** @param array<array-key, mixed> $response */
    public function map(array $response, DriverRequest $request): ResearchResult
    {
        if (data_get($response, 'status') !== 'completed') {
            throw $this->invalidResponse();
        }

        $model = $this->model(data_get($response, 'model'));
        [$content, $results] = $this->output($response);
        $citations = $this->citations($content, $results);

        if ($content === '' || $citations->isEmpty()) {
            throw $this->invalidGroundedResponse();
        }

        $completedAt = $this->completedAt(data_get($response, 'completed_at'));

        return TerminalRedaction::guard(ResearchResult::make(
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
            model: $model,
            usage: $this->usage(data_get($response, 'usage')),
        ), [
            (string) $request->profile->credential,
            $this->optionalBoundedString(data_get($response, 'id')) ?? '',
        ], $this->invalidResponse());
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return array{string, array<string, array{id: string, url: string, title: ?string, snippet: ?string, source: ?string}>}
     */
    private function output(array $response): array
    {
        $output = data_get($response, 'output');

        if (! is_array($output) || ! array_is_list($output)) {
            throw $this->invalidResponse();
        }

        $text = '';
        $results = [];

        foreach ($output as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw $this->invalidResponse();
            }

            $type = data_get($item, 'type');

            if ($type === 'message') {
                $text .= $this->messageText($item);

                continue;
            }

            if ($type === 'search_results') {
                foreach ($this->searchResults($item) as $id => $result) {
                    if (isset($results[$id])) {
                        throw $this->invalidResponse();
                    }

                    $results[$id] = $result;
                }

                continue;
            }

            if (! is_string($type) || ! in_array($type, self::IGNORED_OUTPUT_TYPES, true)) {
                throw $this->invalidResponse();
            }
        }

        return [$text, $results];
    }

    /** @param array<array-key, mixed> $message */
    private function messageText(array $message): string
    {
        $content = data_get($message, 'content');

        if (! is_array($content) || ! array_is_list($content)) {
            throw $this->invalidResponse();
        }

        $text = '';

        foreach ($content as $part) {
            if (! is_array($part) || array_is_list($part) || data_get($part, 'type') !== 'output_text') {
                throw $this->invalidResponse();
            }

            $value = data_get($part, 'text');

            if (! is_string($value)) {
                throw $this->invalidResponse();
            }

            $text .= $value;
        }

        return $text;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array<string, array{id: string, url: string, title: ?string, snippet: ?string, source: ?string}>
     */
    private function searchResults(array $item): array
    {
        $values = data_get($item, 'results');

        if (! is_array($values) || ! array_is_list($values)) {
            throw $this->invalidResponse();
        }

        $results = [];

        foreach ($values as $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw $this->invalidResponse();
            }

            $id = $this->sourceId(data_get($value, 'id'));
            $url = $this->evidenceUrl(data_get($value, 'url'));
            $title = $this->optionalBoundedString(data_get($value, 'title'));
            $snippet = $this->optionalBoundedString(data_get($value, 'snippet'));
            $source = $this->optionalSource(data_get($value, 'source'));

            if ($id === null || $url === null || isset($results[$id])) {
                throw $this->invalidResponse();
            }

            $results[$id] = compact('id', 'url', 'title', 'snippet', 'source');
        }

        return $results;
    }

    /**
     * @param  array<string, array{id: string, url: string, title: ?string, snippet: ?string, source: ?string}>  $results
     * @return Collection<int, Citation>
     */
    private function citations(string $content, array $results): Collection
    {
        preg_match_all(
            '/\[(?:([A-Za-z]+):([A-Za-z0-9._-]+)|([0-9]+))\]/',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );
        $citations = [];
        $seen = [];

        foreach ($matches as $match) {
            $rawSource = $match[1] ?? null;
            $source = is_string($rawSource) ? strtolower($rawSource) : null;
            $id = $source === null ? ($match[3] ?? null) : ($match[2] ?? null);

            if (! is_string($id)) {
                throw $this->invalidGroundedResponse();
            }

            $result = $results[$id] ?? null;

            if (
                ($source !== null && ! array_key_exists($source, self::SOURCE_KINDS))
                || $result === null
                || ($source !== null && $result['source'] !== null && $source !== $result['source'])
            ) {
                throw $this->invalidGroundedResponse();
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $kind = self::SOURCE_KINDS[$result['source'] ?? $source ?? 'web'];
            $citations[] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: $kind,
                    url: $result['url'],
                    providerReference: $result['id'],
                    title: $result['title'],
                ),
                excerpt: $result['snippet'],
                locator: $source === null ? '['.$id.']' : '['.$source.':'.$id.']',
            );
        }

        return collect($citations);
    }

    private function usage(mixed $value): ?Usage
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalidResponse();
        }

        $inputDetails = $this->optionalRecord(data_get($value, 'input_tokens_details'));
        $outputDetails = $this->optionalRecord(data_get($value, 'output_tokens_details'));
        $cost = $this->optionalRecord(data_get($value, 'cost'));

        $promptTokens = $this->optionalNonNegativeInt(data_get($value, 'input_tokens'));
        $completionTokens = $this->optionalNonNegativeInt(data_get($value, 'output_tokens'));
        $totalTokens = $this->optionalNonNegativeInt(data_get($value, 'total_tokens'));

        if ($totalTokens !== null && $promptTokens !== null && $completionTokens !== null && $totalTokens !== $promptTokens + $completionTokens) {
            throw $this->invalidResponse();
        }

        $cacheRead = $this->optionalNonNegativeInt(data_get($inputDetails, 'cache_read_input_tokens'))
            ?? $this->optionalNonNegativeInt(data_get($inputDetails, 'cached_tokens'));
        $cacheCreation = $this->optionalNonNegativeInt(data_get($inputDetails, 'cache_creation_input_tokens'));
        $cacheWrite = $this->optionalNonNegativeInt(data_get($inputDetails, 'cache_write_input_tokens'));

        if ($cacheCreation !== null && $cacheWrite !== null && $cacheCreation !== $cacheWrite) {
            throw $this->invalidResponse();
        }

        $actualCost = $this->cost(data_get($cost, 'total_cost'));
        $currency = data_get($cost, 'currency');

        if ($currency !== null && $currency !== 'USD') {
            throw $this->invalidResponse();
        }

        return new Usage(
            promptTokens: $promptTokens === null ? null : max(0, $promptTokens - ($cacheRead ?? 0)),
            completionTokens: $completionTokens,
            cacheWriteInputTokens: $cacheCreation ?? $cacheWrite,
            cacheReadInputTokens: $cacheRead,
            reasoningTokens: $this->optionalNonNegativeInt(data_get($outputDetails, 'reasoning_tokens')),
            actualCost: $actualCost,
            currency: $actualCost === null ? null : 'USD',
        );
    }

    /** @return array<array-key, mixed> */
    private function optionalRecord(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    private function optionalNonNegativeInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0 || $value > 9_007_199_254_740_991) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    private function cost(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
            throw $this->invalidResponse();
        }

        $encoded = json_encode((float) $value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        if (preg_match('/^\d+\.(\d+)[eE]([+-]?\d+)$/D', $encoded, $matches) === 1) {
            $decimals = max(0, min(18, -(int) $matches[2] + strlen(rtrim($matches[1], '0'))));
            $cost = number_format((float) $value, $decimals, '.', '');
        } else {
            $cost = rtrim(rtrim($encoded, '0'), '.') ?: '0';
        }

        if ($value > 0 && $cost === '0') {
            throw $this->invalidResponse();
        }

        return $cost;
    }

    private function completedAt(mixed $value): CarbonImmutable
    {
        if (! is_int($value) || $value < 0) {
            throw $this->invalidResponse();
        }

        return CarbonImmutable::createFromTimestampUTC($value);
    }

    private function model(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 255 || preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/D', $value) !== 1) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    private function sourceId(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0 && $value <= 9_007_199_254_740_991) {
            return (string) $value;
        }

        if (! is_string($value) || strlen($value) > 255 || preg_match('/^[A-Za-z0-9._-]+$/D', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function optionalSource(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! array_key_exists(strtolower($value), self::SOURCE_KINDS)) {
            throw $this->invalidResponse();
        }

        return strtolower($value);
    }

    private function optionalBoundedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) !== $value || strlen($value) > 10_000) {
            throw $this->invalidResponse();
        }

        return $value === '' ? null : $value;
    }

    private function evidenceUrl(mixed $value): ?string
    {
        return EvidenceUrl::sanitize($value);
    }

    private function invalidGroundedResponse(): DriverException
    {
        return new DriverException(
            'perplexity.invalid_response',
            'Perplexity returned an incomplete grounded Agent response.',
            false,
        );
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'perplexity.invalid_response',
            'Perplexity returned a malformed Agent response.',
            false,
        );
    }
}
