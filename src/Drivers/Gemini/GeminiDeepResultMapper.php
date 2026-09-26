<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Gemini;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\ResearchResultMapper;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;

/** @internal */
final readonly class GeminiDeepResultMapper
{
    public function __construct(private ResearchResultMapper $results) {}

    /** @param array<array-key, mixed> $interaction */
    public function map(array $interaction, DriverRequest|RecoveryRequest $request): ResearchResult
    {
        return $this->results->map(
            response: $this->toAgentResponse($interaction, $request),
            request: $request,
            completedAt: $this->completedAt($interaction),
            usage: $this->usage(data_get($interaction, 'usage')),
            citationByteOffsets: true,
        );
    }

    /** @param array<array-key, mixed> $interaction */
    public function toAgentResponse(array $interaction, DriverRequest|RecoveryRequest $request): AgentResponse
    {
        $text = $this->str(data_get($interaction, 'output_text')) ?? implode("\n", $this->textParts($interaction));
        $citations = $this->citations($interaction);
        if ($text === '' || $citations->isEmpty()) {
            throw $this->invalidResponse();
        }

        return new AgentResponse(
            invocationId: $request->requestId,
            text: $text,
            usage: new TextUsage,
            meta: new Meta(
                provider: $request->profile->provider,
                model: $this->str(data_get($interaction, 'agent'))
                    ?? $this->str(data_get($interaction, 'model'))
                    ?? $request->profile->model,
                citations: $citations,
            ),
        );
    }

    /**
     * @param  array<array-key, mixed>  $interaction
     * @return list<string>
     */
    private function textParts(array $interaction): array
    {
        $parts = [];
        foreach ($this->records(data_get($interaction, 'steps')) as $step) {
            if (data_get($step, 'type') !== 'model_output') {
                continue;
            }

            foreach ($this->records(data_get($step, 'content')) as $content) {
                if (data_get($content, 'type') !== 'text') {
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
     * @param  array<array-key, mixed>  $interaction
     * @return Collection<int, Citation>
     */
    private function citations(array $interaction): Collection
    {
        /** @var list<Citation> $citations */
        $citations = [];
        $seen = [];

        foreach ($this->records(data_get($interaction, 'steps')) as $step) {
            if (data_get($step, 'type') !== 'model_output') {
                continue;
            }

            foreach ($this->records(data_get($step, 'content')) as $content) {
                if (data_get($content, 'type') !== 'text') {
                    continue;
                }

                foreach ($this->records(data_get($content, 'annotations')) as $annotation) {
                    $type = data_get($annotation, 'type');
                    $url = match ($type) {
                        'url_citation', 'place_citation' => $this->evidenceUrl(data_get($annotation, 'url')),
                        'file_citation' => $this->evidenceUrl(data_get($annotation, 'document_uri')),
                        default => null,
                    };
                    if ($url === null || isset($seen[$url])) {
                        continue;
                    }

                    $seen[$url] = true;
                    $title = match ($type) {
                        'place_citation' => $this->str(data_get($annotation, 'name')) ?? $this->str(data_get($annotation, 'title')),
                        'file_citation' => $this->str(data_get($annotation, 'file_name')) ?? $this->str(data_get($annotation, 'title')),
                        default => $this->str(data_get($annotation, 'title')),
                    };
                    $citations[] = new UrlCitation(
                        url: $url,
                        title: $title,
                        startIndex: $this->nonNegativeInt(data_get($annotation, 'start_index')),
                        endIndex: $this->nonNegativeInt(data_get($annotation, 'end_index')),
                    );
                }
            }
        }

        return collect($citations);
    }

    private function usage(mixed $value): Usage
    {
        if (! is_array($value) || array_is_list($value)) {
            return new Usage;
        }

        $input = $this->nonNegativeInt(data_get($value, 'total_input_tokens'));
        $cached = $this->nonNegativeInt(data_get($value, 'total_cached_tokens'));
        $output = $this->nonNegativeInt(data_get($value, 'total_output_tokens'));
        $thought = $this->nonNegativeInt(data_get($value, 'total_thought_tokens'));
        $toolUse = $this->nonNegativeInt(data_get($value, 'total_tool_use_tokens'));

        // Gemini reports thought tokens outside the output token count, so
        // the interchange contract's inclusive completion total folds them
        // in the same way laravel/ai's own Gemini gateway parser does.
        // Gemini reports total_input_tokens as an inclusive total that
        // already contains the cached partition, so the exclusive prompt
        // partition is derived by subtracting it; when that subtraction
        // would go negative, the provider's usage report is internally
        // inconsistent, so the whole input side fails closed to unknown
        // instead of fabricating partitions. total_tool_use_tokens counts
        // tokens in tool-use prompts that Google already bills inside the
        // inclusive input total, so it is carried as an unpriced audit
        // count alongside the priced partitions.
        if ($input !== null && $input - ($cached ?? 0) < 0) {
            $input = null;
            $cached = null;
        }

        return new Usage(
            promptTokens: $input === null ? null : $input - ($cached ?? 0),
            completionTokens: $output === null ? null : $output + ($thought ?? 0),
            cacheReadInputTokens: $cached,
            reasoningTokens: $thought,
            toolUseTokens: $toolUse,
        );
    }

    /** @param array<array-key, mixed> $interaction */
    private function completedAt(array $interaction): CarbonImmutable
    {
        $updated = data_get($interaction, 'updated');
        if ($updated === null) {
            return CarbonImmutable::now();
        }
        if (! is_string($updated)) {
            throw $this->invalidResponse();
        }

        try {
            return CarbonImmutable::parse($updated);
        } catch (\Throwable) {
            throw $this->invalidResponse();
        }
    }

    private function evidenceUrl(mixed $value): ?string
    {
        return EvidenceUrl::sanitize($value);
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

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'gemini_deep.invalid_response',
            'Gemini Deep Research returned an invalid grounded research report.',
            false,
        );
    }
}
