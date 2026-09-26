<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\OpenAi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchResultMapper;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;

/** @internal */
final readonly class OpenAiResearchResultMapper
{
    public function __construct(private ResearchResultMapper $results) {}

    /** @param array<array-key, mixed> $response */
    public function map(array $response, DriverRequest $request): ResearchResult
    {
        return $this->results->map(
            response: $this->toAgentResponse($response, $request),
            request: $request,
            completedAt: $this->completedAt($response),
        );
    }

    /** @param array<array-key, mixed> $response */
    public function toAgentResponse(array $response, DriverRequest $request): AgentResponse
    {
        $parts = $this->outputTextParts($response);
        $citations = $this->citations($response);

        if (! $this->hasCompletedWebSearchCall($response) || $parts === [] || $citations->isEmpty()) {
            throw new DriverException(
                'openai.invalid_response',
                'OpenAI returned an incomplete grounded research report.',
            );
        }

        return new AgentResponse(
            invocationId: $request->requestId,
            text: implode("\n\n", $parts),
            usage: $this->usage(data_get($response, 'usage')),
            meta: new Meta(
                provider: $request->profile->provider,
                model: $this->str(data_get($response, 'model')) ?? $request->profile->model,
                citations: $citations,
            ),
        );
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return list<string>
     */
    private function outputTextParts(array $response): array
    {
        $parts = [];

        foreach ($this->records(data_get($response, 'output')) as $item) {
            if (data_get($item, 'type') !== 'message') {
                continue;
            }

            foreach ($this->records(data_get($item, 'content')) as $content) {
                if (data_get($content, 'type') !== 'output_text') {
                    continue;
                }

                $text = $this->str(data_get($content, 'text'));
                if ($text !== null) {
                    $parts[] = $text;
                }
            }
        }

        return $parts;
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @return Collection<int, Citation>
     */
    private function citations(array $response): Collection
    {
        /** @var list<Citation> $citations */
        $citations = [];
        $seen = [];

        foreach ($this->records(data_get($response, 'output')) as $item) {
            if (data_get($item, 'type') !== 'message') {
                continue;
            }

            foreach ($this->records(data_get($item, 'content')) as $content) {
                if (data_get($content, 'type') !== 'output_text') {
                    continue;
                }

                foreach ($this->records(data_get($content, 'annotations')) as $annotation) {
                    if (data_get($annotation, 'type') !== 'url_citation') {
                        continue;
                    }

                    $url = $this->evidenceUrl(data_get($annotation, 'url'));
                    if ($url === null || isset($seen[$url])) {
                        continue;
                    }

                    $seen[$url] = true;
                    $citations[] = new UrlCitation(
                        url: $url,
                        title: $this->str(data_get($annotation, 'title')),
                        startIndex: $this->nonNegativeInt(data_get($annotation, 'start_index')),
                        endIndex: $this->nonNegativeInt(data_get($annotation, 'end_index')),
                    );
                }
            }
        }

        return collect($citations);
    }

    /** @param array<array-key, mixed> $response */
    private function hasCompletedWebSearchCall(array $response): bool
    {
        foreach ($this->records(data_get($response, 'output')) as $item) {
            if (
                data_get($item, 'type') === 'web_search_call'
                && data_get($item, 'status') === 'completed'
                && in_array(data_get($item, 'action.type'), ['search', 'open_page', 'find_in_page'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The webhook payload's OpenAI Responses API usage. Its input_tokens
     * already totals the cached partition, which matches the laravel/ai 1.0
     * TextUsage contract, so the counts pass straight through and the
     * interchange mapper derives the exclusive prompt partition.
     * cache_write_input_tokens is not an OpenAI Responses API field and
     * stays unreported, as it always was on this route.
     */
    private function usage(mixed $value): TextUsage
    {
        if (! is_array($value) || array_is_list($value)) {
            return new TextUsage;
        }

        return new TextUsage(
            inputTokens: $this->nonNegativeInt(data_get($value, 'input_tokens')) ?? 0,
            outputTokens: $this->nonNegativeInt(data_get($value, 'output_tokens')) ?? 0,
            cacheReadInputTokens: $this->nonNegativeInt(data_get($value, 'input_tokens_details.cached_tokens')),
            reasoningTokens: $this->nonNegativeInt(data_get($value, 'output_tokens_details.reasoning_tokens')),
        );
    }

    /** @param array<array-key, mixed> $response */
    private function completedAt(array $response): CarbonImmutable
    {
        $timestamp = data_get($response, 'completed_at');

        if ($timestamp === null) {
            return CarbonImmutable::now();
        }

        if (is_int($timestamp) && $timestamp >= 0) {
            return CarbonImmutable::createFromTimestampUTC($timestamp);
        }

        throw new DriverException(
            'openai.invalid_response',
            'OpenAI returned an invalid completion timestamp.',
        );
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) !== $value || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
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

    /** @return list<array<array-key, mixed>> */
    private function records(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
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
