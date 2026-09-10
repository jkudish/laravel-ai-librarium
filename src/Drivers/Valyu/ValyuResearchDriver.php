<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Valyu;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Validation\Validator as LaravelValidator;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
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
use Throwable;

/** Runs one durable Valyu DeepResearch task to a terminal report. */
final readonly class ValyuResearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.valyu.ai/v1/deepresearch/tasks';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 30.0;

    private const float CANCELLATION_TIMEOUT_SECONDS = 5.0;

    private const int POLL_INTERVAL_SECONDS = 5;

    private const array COUNTRY_CODES = [
        'ALL', 'AR', 'AU', 'AT', 'BE', 'BR', 'CA', 'CL', 'DK', 'FI', 'FR', 'DE', 'HK', 'IN', 'ID', 'IT', 'JP', 'KR',
        'MY', 'MX', 'NL', 'NZ', 'NO', 'CN', 'PL', 'PT', 'PH', 'RU', 'SA', 'ZA', 'ES', 'SE', 'CH', 'TW', 'TR', 'GB', 'US',
    ];

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'mode' => ['sometimes', 'string', 'in:standard'],
            'research_strategy' => ['sometimes', 'string', 'filled', 'max:15000'],
            'report_format' => ['sometimes', 'string', 'filled', 'max:15000'],
            'search' => ['sometimes', 'array:search_type,included_sources,excluded_sources,source_biases,start_date,end_date,country_code'],
            'search.search_type' => ['sometimes', 'string', 'in:all,web,proprietary'],
            'search.included_sources' => ['sometimes', 'array', 'list', 'min:1', 'max:20', 'prohibits:options.search.excluded_sources'],
            'search.included_sources.*' => ['string', 'filled'],
            'search.excluded_sources' => ['sometimes', 'array', 'list', 'min:1', 'max:20', 'prohibits:options.search.included_sources'],
            'search.excluded_sources.*' => ['string', 'filled'],
            'search.source_biases' => ['sometimes', 'array', static function (string $attribute, mixed $value, callable $fail): void {
                if (is_array($value) && array_is_list($value)) {
                    $fail("The {$attribute} field must be an object.");
                }
            }],
            'search.source_biases.*' => ['integer', 'between:-5,5'],
            'search.start_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:options.search.end_date'],
            'search.end_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:options.search.start_date'],
            'search.country_code' => ['sometimes', 'string', 'in:'.implode(',', self::COUNTRY_CODES)],
            'urls' => ['sometimes', 'array', 'list', 'min:1', 'max:10'],
            'urls.*' => ['url:http,https', static function (string $attribute, mixed $value, callable $fail): void {
                if (ValyuSupport::evidenceUrl($value) === null) {
                    $fail("The {$attribute} field must be a safe HTTP URL.");
                }
            }],
            'output_formats' => ['sometimes', 'array', 'list', static function (string $attribute, mixed $value, callable $fail): void {
                if (! is_array($value) || $value !== ['markdown']) {
                    $fail("The {$attribute} field is invalid.");
                }
            }],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $taskId = $this->submit($request);
        $request->progress('Valyu is researching in the background.');
        $cleanup = true;

        try {
            while (true) {
                $this->waitBeforePolling($request);
                $task = $this->status($request, $taskId);
                $status = data_get($task, 'status');

                if ($status === 'completed') {
                    $cleanup = false;
                    $terminal = $this->status($request, $taskId);
                    if (data_get($terminal, 'status') !== 'completed') {
                        throw $this->invalidResponse();
                    }

                    return $this->map($request, $terminal);
                }

                if (in_array($status, ['failed', 'cancelled'], true)) {
                    $cleanup = false;
                    throw new DriverException(
                        'valyu_research.'.($status === 'failed' ? 'failed' : 'cancelled'),
                        $status === 'failed' ? 'Valyu could not complete the research report.' : 'The Valyu research report was cancelled.',
                        false,
                    );
                }

                if (in_array($status, ['awaiting_input', 'paused'], true)) {
                    throw new DriverException('valyu_research.interaction_required', 'Valyu requested unsupported human interaction.', false);
                }

                if (! in_array($status, ['queued', 'running'], true)) {
                    throw $this->invalidResponse();
                }
            }
        } finally {
            if ($cleanup) {
                $this->cancelBestEffort($request, $taskId);
            }
        }
    }

    private function validateRequest(DriverRequest $request): void
    {
        $profile = $request->profile;
        $searchType = data_get($profile->options, 'search.search_type');
        $expectedCorpora = match ($searchType) {
            'web' => [Corpus::Web],
            'proprietary' => [Corpus::Specialized],
            default => [Corpus::Web, Corpus::Specialized],
        };

        if ($profile->id !== 'valyu-research'
            || $profile->provider !== 'valyu'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::ResearchReport
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== $expectedCorpora
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]
        ) {
            throw new DriverException('valyu_research.invalid_profile', 'The Valyu Research Profile has incompatible provider or provenance semantics.', false);
        }

        $options = $profile->options;
        $validator = Validator::make(
            ['query' => $request->prompt, 'options' => $options],
            [
                'query' => ['required', 'string', 'max:25000'],
                'options' => ['array:mode,research_strategy,report_format,search,urls,output_formats'],
                ...$this->prefixedRules(),
            ],
        );
        $validator->after(function (LaravelValidator $validator) use ($options): void {
            $strategy = $options['research_strategy'] ?? null;
            $format = $options['report_format'] ?? null;
            if ((is_string($strategy) ? mb_strlen($strategy) : 0) + (is_string($format) ? mb_strlen($format) : 0) > 15_000) {
                $validator->errors()->add('options.report_format', 'Research strategy and report format are too long.');
            }

            $formats = $options['output_formats'] ?? ['markdown'];
            if (! is_array($formats) || $formats !== ['markdown']) {
                $validator->errors()->add('options.output_formats', 'The output formats are invalid.');
            }

            $urls = $options['urls'] ?? [];
            if (is_array($urls)) {
                foreach ($urls as $url) {
                    if (ValyuSupport::evidenceUrl($url) === null) {
                        $validator->errors()->add('options.urls', 'Context URLs must be safe HTTP URLs.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw new DriverException('valyu_research.invalid_request', 'The Valyu Research query or options are invalid.', false);
        }
        if (trim($request->prompt) === '') {
            throw new DriverException('valyu_research.invalid_request', 'The Valyu Research query or options are invalid.', false);
        }
        if (blank($profile->credential)) {
            throw new DriverException('valyu_research.authentication', 'The Valyu provider is not configured.', false);
        }

        $this->remainingSeconds($request, 'The Valyu Research request could not start before the research deadline.');
    }

    /** @return array<string, mixed> */
    private function prefixedRules(): array
    {
        $rules = [];
        foreach (self::profileOptionRules() as $key => $rule) {
            $rules['options.'.$key] = $rule;
        }

        return $rules;
    }

    private function submit(DriverRequest $request): string
    {
        try {
            $response = $this->send($request, 'post', self::ENDPOINT, $this->payload($request));
        } catch (ConnectionException) {
            throw new DriverException(
                'valyu_research.submit_connection',
                'The Valyu paid research submission did not return a response and will not be retried automatically.',
                false,
            );
        }

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException(
                'valyu_research.deadline_exceeded',
                'Valyu did not accept the research request before the research deadline.',
                false,
            );
        }
        if ($response->status() !== 202) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidSubmission();
        }
        $taskId = ValyuSupport::providerReference(data_get($json, 'deepresearch_id'));
        if ($taskId === null
            || ! in_array(data_get($json, 'status'), ['queued', 'running'], true)
            || data_get($json, 'mode') !== 'standard'
            || ValyuSupport::timestamp(data_get($json, 'created_at')) === null) {
            throw $this->invalidSubmission();
        }

        return $taskId;
    }

    /** @return array<array-key, mixed> */
    private function status(DriverRequest $request, string $taskId): array
    {
        while (true) {
            try {
                $response = $this->send($request, 'get', self::ENDPOINT.'/'.rawurlencode($taskId).'/status');
            } catch (ConnectionException) {
                if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                    throw $this->deadlineExceeded();
                }

                $this->waitBeforePolling($request);

                continue;
            }

            $this->remainingSeconds($request, 'Valyu did not complete before the research deadline.');
            if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
                $this->waitBeforePolling($request);

                continue;
            }
            if ($response->status() !== 200) {
                throw $this->httpFailure($response);
            }

            $json = $this->json($response);
            if (data_get($json, 'deepresearch_id') !== $taskId) {
                throw $this->invalidResponse();
            }

            return $json;
        }
    }

    /** @return array<string, mixed> */
    private function payload(DriverRequest $request): array
    {
        $options = $request->profile->options;
        $payload = [
            'query' => $request->prompt,
            'mode' => $options['mode'] ?? 'standard',
            'output_formats' => $options['output_formats'] ?? ['markdown'],
        ];

        foreach (['research_strategy', 'report_format', 'urls'] as $key) {
            if (array_key_exists($key, $options)) {
                $payload[$key] = $options[$key];
            }
        }
        if (isset($options['search']) && is_array($options['search'])) {
            $payload['search'] = $options['search'];
        }

        return $payload;
    }

    /** @param array<array-key, mixed> $task */
    private function map(DriverRequest $request, array $task): ResearchResult
    {
        $content = ValyuSupport::string(data_get($task, 'output'));
        $sources = data_get($task, 'sources', []);
        if (data_get($task, 'output_type') !== 'markdown'
            || $content === null
            || ! is_array($sources)
            || ! array_is_list($sources)) {
            throw $this->invalidResponse();
        }

        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $safeSources = [];
        foreach ($sources as $source) {
            if (! is_array($source) || array_is_list($source)) {
                throw $this->invalidResponse();
            }

            $url = ValyuSupport::evidenceUrl(data_get($source, 'url'));
            if ($url === null) {
                continue;
            }

            $title = ValyuSupport::string(data_get($source, 'title'));
            $publisher = ValyuSupport::string(data_get($source, 'source'));
            $excerpt = ValyuSupport::string(data_get($source, 'snippet'))
                ?? ValyuSupport::string(data_get($source, 'description'));
            $locator = ValyuSupport::string(data_get($source, 'fragment'));

            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    providerReference: ValyuSupport::providerReference(data_get($source, 'source')),
                    title: $title,
                    publisher: $publisher,
                ),
                excerpt: $excerpt,
                locator: $locator,
            ));
            $safeSources[] = array_filter([
                'publisher' => $publisher,
                'doi' => ValyuSupport::string(data_get($source, 'doi')),
                'word_count' => is_int(data_get($source, 'word_count')) && data_get($source, 'word_count') >= 0
                    ? data_get($source, 'word_count')
                    : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $completedAt = ValyuSupport::timestamp(data_get($task, 'completed_at')) ?? CarbonImmutable::now();
        $mode = data_get($task, 'mode');
        if ($mode !== 'standard') {
            throw $this->invalidResponse();
        }

        $result = ResearchResult::make(
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
            usage: ValyuSupport::usage(data_get($task, 'cost')),
            providerMeta: (object) [
                'valyu:research' => [
                    'mode' => $mode,
                    'execution' => 'background',
                    'resumability' => 'durable_provider_task',
                    'sources' => $safeSources,
                    'artifacts' => $this->artifacts($task),
                ],
            ],
        );

        $wire = json_encode($result->toArray(), JSON_THROW_ON_ERROR);
        foreach ([(string) $request->profile->credential, ValyuSupport::string(data_get($task, 'deepresearch_id')) ?? ''] as $privateValue) {
            if ($privateValue !== '' && str_contains($wire, $privateValue)) {
                throw $this->invalidResponse();
            }
        }

        return $result;
    }

    /** @param array<array-key, mixed> $task
     * @return list<array<string, mixed>>
     */
    private function artifacts(array $task): array
    {
        $artifacts = [];
        if (ValyuSupport::string(data_get($task, 'pdf_url')) !== null) {
            $artifacts[] = ['kind' => 'pdf'];
        }

        foreach ([['images', 'image'], ['deliverables', 'deliverable']] as [$key, $defaultKind]) {
            $values = data_get($task, $key, []);
            if (! is_array($values) || ! array_is_list($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (! is_array($value) || array_is_list($value)) {
                    continue;
                }
                $kind = ValyuSupport::string(data_get($value, $key === 'images' ? 'image_type' : 'type')) ?? $defaultKind;
                $artifacts[] = array_filter([
                    'kind' => $kind,
                    'provider_asset_id' => ValyuSupport::providerReference(data_get($value, $key === 'images' ? 'image_id' : 'id')),
                    'status' => ValyuSupport::string(data_get($value, 'status')),
                    'title' => ValyuSupport::string(data_get($value, 'title')),
                    'row_count' => is_int(data_get($value, 'row_count')) ? data_get($value, 'row_count') : null,
                    'column_count' => is_int(data_get($value, 'column_count')) ? data_get($value, 'column_count') : null,
                ], static fn (mixed $item): bool => $item !== null);
            }
        }

        return $artifacts;
    }

    /** @param array<string, mixed> $payload */
    private function send(DriverRequest $request, string $method, string $url, array $payload = []): Response
    {
        $remaining = $this->remainingSeconds($request, 'Valyu did not complete before the research deadline.');
        $pending = Http::withHeaders(['X-API-Key' => (string) $request->profile->credential])
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

        return $method === 'post' ? $pending->post($url, $payload) : $pending->get($url);
    }

    private function waitBeforePolling(DriverRequest $request): void
    {
        $remaining = $this->remainingSeconds($request, 'Valyu did not complete before the research deadline.');
        Sleep::for(min(self::POLL_INTERVAL_SECONDS, $remaining))->seconds();
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    private function cancelBestEffort(DriverRequest $request, string $taskId): void
    {
        try {
            Http::withHeaders(['X-API-Key' => (string) $request->profile->credential])
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::CANCELLATION_TIMEOUT_SECONDS)
                ->post(self::ENDPOINT.'/'.rawurlencode($taskId).'/cancel');
        } catch (Throwable) {
            // The original terminal error remains authoritative.
        }
    }

    private function remainingSeconds(DriverRequest $request, string $message): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw new DriverException('valyu_research.deadline_exceeded', $message, false);
        }

        return $seconds;
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException('valyu_research.deadline_exceeded', 'Valyu did not complete before the research deadline.', false);
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('valyu_research.authentication', 'Valyu rejected the configured credential.', false),
            $response->status() === 402 => new DriverException('valyu_research.insufficient_credit', 'The Valyu account has insufficient API credit.', false),
            $response->status() === 403 => new DriverException('valyu_research.authorization', 'Valyu denied this request.', false),
            $response->status() === 404 => new DriverException('valyu_research.task_missing', 'The Valyu research task is no longer available.', false),
            $response->status() === 408 => new DriverException('valyu_research.timeout', 'Valyu Research timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('valyu_research.rate_limited', 'Valyu Research rate limited this request.', false),
            $response->serverError() => new DriverException('valyu_research.unavailable', 'Valyu Research is temporarily unavailable.', false),
            default => new DriverException('valyu_research.invalid_request', 'Valyu rejected this research request.', false),
        };
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        return $json;
    }

    private function invalidSubmission(): DriverException
    {
        return new DriverException('valyu_research.invalid_submission', 'Valyu returned an invalid paid research submission response.', false);
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('valyu_research.invalid_response', 'Valyu returned an invalid research task response.', false);
    }
}
