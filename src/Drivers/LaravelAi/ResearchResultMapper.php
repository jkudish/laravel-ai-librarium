<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\LaravelAi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
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
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use Jkudish\LaravelAiPricing\Adapters\LaravelAiProviderCostExtractor;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\UrlCitation;
use stdClass;

/** @internal */
final readonly class ResearchResultMapper
{
    public function map(
        AgentResponse $response,
        DriverRequest|RecoveryRequest $request,
        bool $usesWebSearch = true,
        ?CarbonImmutable $completedAt = null,
        ?Usage $usage = null,
    ): ResearchResult {
        $text = data_get($response, 'text');
        $provider = data_get($response, 'meta.provider');
        $model = data_get($response, 'meta.model');
        $citations = $this->citations($response);
        $isGrounded = $usesWebSearch && $citations->isNotEmpty();
        $completedAt ??= CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: is_string($text) ? $text : '',
            requestedProfile: $request->requestedProfile->id,
            provider: is_string($provider) && $provider !== '' ? $provider : $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $isGrounded ? $request->profile->resultKind : ResultKind::ModelAnswer,
                retrievalMethods: $isGrounded ? $request->profile->retrievalMethods : $this->modelOnlyRetrievalMethods(),
                corpora: $isGrounded ? $request->profile->corpora : collect($this->emptyCorpora()),
                observedAt: $completedAt,
            ),
            citations: $citations,
            completedAt: $completedAt,
            model: is_string($model) && $model !== '' ? $model : $request->profile->model,
            usage: $usage ?? $this->usage($response, $request->profile->provider ?? ''),
            providerMeta: $this->providerMeta($response),
        );
    }

    /** @return array<int, Corpus> */
    private function emptyCorpora(): array
    {
        return [];
    }

    /** @return Collection<int, RetrievalMethod> */
    private function modelOnlyRetrievalMethods(): Collection
    {
        return collect($this->modelOnlyRetrievalMethodValues());
    }

    /** @return list<RetrievalMethod> */
    private function modelOnlyRetrievalMethodValues(): array
    {
        return [RetrievalMethod::ModelOnly];
    }

    /** @return Collection<int, Citation> */
    private function citations(AgentResponse $response): Collection
    {
        $steps = data_get($response, 'steps');
        $steps = $steps instanceof Collection ? $steps : collect();

        $citations = $steps->flatMap(function (mixed $step): array {
            if (! $step instanceof Step) {
                return [];
            }

            $items = data_get($step, 'meta.citations');

            return $items instanceof Collection ? $items->all() : [];
        });

        if ($steps->isEmpty()) {
            $items = data_get($response, 'meta.citations');
            $citations = $items instanceof Collection ? $items : collect();
        }

        return $citations
            ->filter(static fn (mixed $citation): bool => $citation instanceof UrlCitation && filled(data_get($citation, 'url')))
            ->unique(static fn (UrlCitation $citation): mixed => data_get($citation, 'url'))
            ->map(function (UrlCitation $citation): Citation {
                $url = data_get($citation, 'url');
                $title = data_get($citation, 'title');

                return Citation::make(
                    derivation: CitationDerivation::ProviderReported,
                    source: new Source(
                        kind: SourceKind::WebPage,
                        url: is_string($url) ? $url : null,
                        title: is_string($title) && $title !== '' ? $title : null,
                    ),
                    locator: $this->locator($citation),
                );
            })
            ->values();
    }

    private function locator(UrlCitation $citation): ?string
    {
        $start = data_get($citation, 'startIndex');
        $end = data_get($citation, 'endIndex');

        if (! is_int($start) && ! is_int($end)) {
            return null;
        }

        return 'characters:'.(is_int($start) ? $start : '?').'-'.(is_int($end) ? $end : '?');
    }

    private function usage(AgentResponse $response, string $provider): Usage
    {
        // The pricing adapter owns OpenRouter's usage.cost -> USD mapping and
        // all-step completeness. It sums decimal amounts, never token quotes
        // or the aggregate response on top of its steps. Do not round a charge
        // to fit the interchange contract's maximum 18 fractional digits.
        $cost = (new LaravelAiProviderCostExtractor)->extract($response, $provider);
        $actualCost = $cost === null ? null : (string) $cost->amount;
        if ($actualCost !== null) {
            if (str_contains($actualCost, '.')) {
                $actualCost = rtrim(rtrim($actualCost, '0'), '.');
            }
            if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/', $actualCost) !== 1) {
                $actualCost = null;
            }
        }

        return new Usage(
            promptTokens: $this->promptTokens($response),
            completionTokens: $this->completionTokens($response),
            cacheWriteInputTokens: $this->integer($response, 'cacheWriteInputTokens'),
            cacheReadInputTokens: $this->integer($response, 'cacheReadInputTokens'),
            reasoningTokens: $this->integer($response, 'reasoningTokens'),
            serverToolSearchRequests: $this->serverToolSearchRequests($response),
            actualCost: $actualCost,
            currency: $actualCost !== null ? $cost?->currency : null,
        );
    }

    /**
     * laravel/ai 1.0 reports inputTokens as a total that always includes the
     * cached and cache-written partitions, so the interchange contract's
     * exclusive prompt count is derived by subtracting them. laravel/ai 0.x
     * reported promptTokens already exclusive of both partitions; its shape
     * is detected by the absence of the renamed inputTokens field.
     */
    private function promptTokens(AgentResponse $response): ?int
    {
        if (data_get($response, 'usage.inputTokens') !== null) {
            $input = $this->integer($response, 'inputTokens');
            $read = $this->integer($response, 'cacheReadInputTokens');
            $write = $this->integer($response, 'cacheWriteInputTokens');

            return $input === null ? null : max(0, $input - ($read ?? 0) - ($write ?? 0));
        }

        return $this->integer($response, 'promptTokens');
    }

    /**
     * laravel/ai 1.0 renamed completionTokens to outputTokens; the count
     * itself kept its 0.x semantics and includes reasoning tokens.
     */
    private function completionTokens(AgentResponse $response): ?int
    {
        return data_get($response, 'usage.outputTokens') !== null
            ? $this->integer($response, 'outputTokens')
            : $this->integer($response, 'completionTokens');
    }

    /**
     * The SDK usage value object carries only token metrics, so the engine's
     * search request count is read from the raw provider responses —
     * current usage.server_tool_use_details.web_search_requests, with
     * compatibility for legacy usage.server_tool_use.web_search_requests —
     * and summed across steps the way the SDK sums tokens. When both fields
     * are present on one response, only the current field is used.
     *
     * The count is an all-or-nothing measurement: every expected step must
     * carry a raw provider response reporting a nonnegative integer count.
     * A malformed present current field is never masked by a valid legacy
     * field. A missing raw response, a missing or malformed count on any
     * step, or a sum that cannot be represented exactly makes the metric
     * unavailable, because a partial sum would silently understate a billed
     * quantity. An explicit zero on every step stays zero.
     */
    private function serverToolSearchRequests(AgentResponse $response): ?int
    {
        $rawResponses = $this->rawResponses($response);

        if ($rawResponses === []) {
            return null;
        }

        $total = 0;

        foreach ($rawResponses as $raw) {
            $json = $raw->json();
            $usage = is_array($json) ? ($json['usage'] ?? null) : null;

            if (! is_array($usage)) {
                return null;
            }

            $value = null;
            if (array_key_exists('server_tool_use_details', $usage)) {
                $details = $usage['server_tool_use_details'];

                if (! is_array($details)) {
                    return null;
                }

                if (array_key_exists('web_search_requests', $details)) {
                    $value = $details['web_search_requests'];
                } else {
                    $value = data_get($usage, 'server_tool_use.web_search_requests');
                }
            } else {
                $value = data_get($usage, 'server_tool_use.web_search_requests');
            }

            if (! is_int($value) || $value < 0) {
                return null;
            }

            if ($value > PHP_INT_MAX - $total) {
                return null;
            }

            $total += $value;
        }

        return $total;
    }

    private function integer(AgentResponse $response, string $metric): ?int
    {
        $value = data_get($response, 'usage.'.$metric);

        if (! is_int($value)) {
            return null;
        }

        return $value !== 0 || $this->reported($response, $metric) ? $value : null;
    }

    private function reported(AgentResponse $response, string $metric): bool
    {
        $paths = match ($metric) {
            'promptTokens' => ['usage.input_tokens', 'usage.prompt_tokens', 'usageMetadata.promptTokenCount'],
            'completionTokens' => ['usage.output_tokens', 'usage.completion_tokens', 'usageMetadata.candidatesTokenCount'],
            'inputTokens' => ['usage.input_tokens', 'usage.prompt_tokens', 'usageMetadata.promptTokenCount'],
            'outputTokens' => ['usage.output_tokens', 'usage.completion_tokens', 'usageMetadata.candidatesTokenCount'],
            'cacheWriteInputTokens' => ['usage.input_tokens_details.cache_write_tokens', 'usage.prompt_tokens_details.cache_write_tokens', 'usage.cache_creation_input_tokens'],
            'cacheReadInputTokens' => ['usage.input_tokens_details.cached_tokens', 'usage.prompt_tokens_details.cached_tokens', 'usage.cache_read_input_tokens', 'usageMetadata.cachedContentTokenCount'],
            'reasoningTokens' => ['usage.output_tokens_details.reasoning_tokens', 'usage.completion_tokens_details.reasoning_tokens', 'usageMetadata.thoughtsTokenCount'],
            default => [],
        };

        foreach ($this->rawResponses($response) as $raw) {
            $json = $raw->json();
            if (! is_array($json)) {
                continue;
            }

            foreach ($paths as $path) {
                if (data_get($json, $path) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Expected raw provider responses for metering. When the response has
     * steps, every step is expected to carry a raw response; one that is
     * missing means the provider payload for that step cannot be inspected,
     * so no list is returned at all rather than quietly dropping the step.
     *
     * @return list<Response>
     */
    private function rawResponses(AgentResponse $response): array
    {
        if ($response->steps->isNotEmpty()) {
            $rawResponses = [];

            foreach ($response->steps as $step) {
                if (! $step->raw instanceof Response) {
                    return [];
                }

                $rawResponses[] = $step->raw;
            }

            return $rawResponses;
        }

        return $response->raw instanceof Response ? [$response->raw] : [];
    }

    private function providerMeta(AgentResponse $response): ?stdClass
    {
        $invocationId = data_get($response, 'invocationId');
        $steps = data_get($response, 'steps');
        if (! is_string($invocationId) || $invocationId === '') {
            return null;
        }

        return (object) [
            'invocation_id' => $invocationId,
            'step_count' => $steps instanceof Collection ? $steps->count() : 0,
        ];
    }
}
