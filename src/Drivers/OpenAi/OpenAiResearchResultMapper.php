<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\OpenAi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchResultMapper;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\Usage as ResearchUsage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;

/**
 * @internal
 *
 * @phpstan-type ReportedUsage array{input: ?int, output: ?int, cached: ?int, reasoning: ?int}
 */
final readonly class OpenAiResearchResultMapper
{
    private const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    private const int MAX_UNIX_TIMESTAMP = 253_402_300_799;

    public function __construct(private ResearchResultMapper $results) {}

    /** @param array<array-key, mixed> $response */
    public function map(array $response, DriverRequest|RecoveryRequest $request): ResearchResult
    {
        $reported = $this->reportedUsage($response);
        $result = $this->results->map(
            response: $this->agentResponse($response, $request, $reported),
            request: $request,
            completedAt: $this->completedAt($response),
        );

        return new ResearchResult(
            id: $result->id,
            contentFormat: $result->contentFormat,
            content: $result->content,
            requestedProfile: $result->requestedProfile,
            provider: $result->provider,
            profile: $result->profile,
            provenance: $result->provenance,
            citations: $result->citations,
            completedAt: $result->completedAt,
            model: $result->model,
            fallbackReason: $result->fallbackReason,
            usage: $this->researchUsage($reported),
            providerMeta: $result->providerMeta,
        );
    }

    /** @param array<array-key, mixed> $response */
    public function toAgentResponse(array $response, DriverRequest|RecoveryRequest $request): AgentResponse
    {
        return $this->agentResponse($response, $request, $this->reportedUsage($response));
    }

    /**
     * @param  array<array-key, mixed>  $response
     * @param  ReportedUsage|null  $usage
     */
    private function agentResponse(array $response, DriverRequest|RecoveryRequest $request, ?array $usage): AgentResponse
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
            usage: new TextUsage(
                inputTokens: $usage['input'] ?? 0,
                outputTokens: $usage['output'] ?? 0,
                cacheReadInputTokens: $usage['cached'] ?? null,
                reasoningTokens: $usage['reasoning'] ?? null,
            ),
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
     * The OpenAI Responses API usage record, validated strictly. An absent
     * record stays unknown; a present but malformed record fails closed
     * rather than being read as zero. Its input_tokens already totals the
     * cached partition, which matches the laravel/ai 1.0 TextUsage contract.
     * cache_write_input_tokens is not an OpenAI Responses API field and
     * stays unreported, as it always was on this route.
     *
     * @param  array<array-key, mixed>  $response
     * @return ReportedUsage|null
     */
    private function reportedUsage(array $response): ?array
    {
        if (! array_key_exists('usage', $response)) {
            return null;
        }

        $value = $response['usage'];
        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalidUsage();
        }

        $inputDetails = $this->optionalRecord($value, 'input_tokens_details');
        $outputDetails = $this->optionalRecord($value, 'output_tokens_details');
        $inputTokens = $this->optionalNonNegativeInt($value, 'input_tokens');
        $outputTokens = $this->optionalNonNegativeInt($value, 'output_tokens');
        $totalTokens = $this->optionalNonNegativeInt($value, 'total_tokens');

        if ($totalTokens !== null && $inputTokens !== null && $outputTokens !== null && $totalTokens !== $inputTokens + $outputTokens) {
            throw $this->invalidUsage();
        }

        return [
            'input' => $inputTokens,
            'output' => $outputTokens,
            'cached' => $this->optionalNonNegativeInt($inputDetails, 'cached_tokens'),
            'reasoning' => $this->optionalNonNegativeInt($outputDetails, 'reasoning_tokens'),
        ];
    }

    /**
     * Derive the interchange's exclusive prompt partition. When the cached
     * partition exceeds the inclusive input total, the provider's report is
     * internally inconsistent, so the input side is unknown rather than a
     * fabricated split, matching the shared Laravel AI mapper.
     *
     * @param  ReportedUsage|null  $usage
     */
    private function researchUsage(?array $usage): ?ResearchUsage
    {
        if ($usage === null) {
            return null;
        }

        $input = $usage['input'];
        $cached = $usage['cached'];
        $consistent = $input === null || $input - ($cached ?? 0) >= 0;

        return new ResearchUsage(
            promptTokens: $input !== null && $consistent ? $input - ($cached ?? 0) : null,
            completionTokens: $usage['output'],
            cacheReadInputTokens: $consistent ? $cached : null,
            reasoningTokens: $usage['reasoning'],
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function optionalRecord(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        $record = $value[$key];
        if (! is_array($record) || array_is_list($record)) {
            throw $this->invalidUsage();
        }

        return $record;
    }

    /** @param array<array-key, mixed> $value */
    private function optionalNonNegativeInt(array $value, string $key): ?int
    {
        if (! array_key_exists($key, $value)) {
            return null;
        }

        $number = $value[$key];
        if (! is_int($number) || $number < 0 || $number > self::MAX_SAFE_INTEGER) {
            throw $this->invalidUsage();
        }

        return $number;
    }

    private function invalidUsage(): DriverException
    {
        return new DriverException(
            'openai.invalid_response',
            'OpenAI returned invalid usage data.',
        );
    }

    /** @param array<array-key, mixed> $response */
    private function completedAt(array $response): CarbonImmutable
    {
        $timestamp = data_get($response, 'completed_at');

        if ($timestamp === null) {
            return CarbonImmutable::now();
        }

        if (is_int($timestamp) && $timestamp >= 0 && $timestamp <= self::MAX_UNIX_TIMESTAMP) {
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
