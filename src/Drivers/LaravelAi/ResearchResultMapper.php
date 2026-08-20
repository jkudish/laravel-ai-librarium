<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\LaravelAi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\UrlCitation;
use stdClass;

/** @internal */
final readonly class ResearchResultMapper
{
    public function map(
        AgentResponse $response,
        DriverRequest $request,
        bool $usesWebSearch = true,
        ?CarbonImmutable $completedAt = null,
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
                corpora: $isGrounded ? $request->profile->corpora : collect(),
                observedAt: $completedAt,
            ),
            citations: $citations,
            completedAt: $completedAt,
            model: is_string($model) && $model !== '' ? $model : $request->profile->model,
            usage: $this->usage($response),
            providerMeta: $this->providerMeta($response),
        );
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

    private function usage(AgentResponse $response): Usage
    {
        return new Usage(
            promptTokens: $this->integer($response, 'usage.promptTokens'),
            completionTokens: $this->integer($response, 'usage.completionTokens'),
            cacheWriteInputTokens: $this->integer($response, 'usage.cacheWriteInputTokens'),
            cacheReadInputTokens: $this->integer($response, 'usage.cacheReadInputTokens'),
            reasoningTokens: $this->integer($response, 'usage.reasoningTokens'),
        );
    }

    private function integer(AgentResponse $response, string $key): int
    {
        $value = data_get($response, $key);

        return is_int($value) ? $value : 0;
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
