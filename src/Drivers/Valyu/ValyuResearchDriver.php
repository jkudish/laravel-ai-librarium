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
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Recovery\DurableSubmission;
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
use Throwable;

/** Runs one durable Valyu DeepResearch task to a terminal report. */
final readonly class ValyuResearchDriver implements DurableDriver
{
    private const string ENDPOINT = 'https://api.valyu.ai/v1/deepresearch/tasks';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 30.0;

    private const float CANCELLATION_TIMEOUT_SECONDS = 5.0;

    private const int POLL_INTERVAL_SECONDS = 5;

    /**
     * Every DeepResearch mode, mapped to its default polling deadline in
     * seconds: Valyu's recommended client timeout for the mode
     * (docs.valyu.ai/guides/deepresearch, checked 2026-10-11), which covers
     * its documented runtimes of ~5 min, ~10-20 min, up to ~90 min, and up to
     * ~180 min. A Profile's request_timeout_seconds replaces it; the shared
     * research deadline always caps it.
     *
     * @var array<string, int>
     */
    public const array MODE_DEADLINE_SECONDS = [
        'fast' => 600,
        'standard' => 1800,
        'heavy' => 7200,
        'max' => 10800,
    ];

    /** Valyu bills PDF output inside the fixed task price; it is not a deliverable. */
    private const array OUTPUT_FORMATS = [['markdown'], ['markdown', 'pdf']];

    /** The signed PDF link is exposed by default; ['markdown'] opts out. */
    private const array DEFAULT_OUTPUT_FORMATS = ['markdown', 'pdf'];

    private const array COUNTRY_CODES = [
        'ALL', 'AR', 'AU', 'AT', 'BE', 'BR', 'CA', 'CL', 'DK', 'FI', 'FR', 'DE', 'HK', 'IN', 'ID', 'IT', 'JP', 'KR',
        'MY', 'MX', 'NL', 'NZ', 'NO', 'CN', 'PL', 'PT', 'PH', 'RU', 'SA', 'ZA', 'ES', 'SE', 'CH', 'TW', 'TR', 'GB', 'US',
    ];

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'mode' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::MODE_DEADLINE_SECONDS))],
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
                if (! in_array($value, self::OUTPUT_FORMATS, true)) {
                    $fail("The {$attribute} field is invalid.");
                }
            }],
            'request_timeout_seconds' => ['sometimes', 'integer:strict', 'min:1', 'max:86400'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $timeout = $request->profile->options['request_timeout_seconds'] ?? null;
        $request = $request->withinSeconds(is_int($timeout) ? $timeout : self::MODE_DEADLINE_SECONDS[$this->mode($request)]);
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
                    $terminal = $this->status($request, $taskId, Operation::Retrieve);
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

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        $this->validateRequest($request);

        return new DurableSubmission(reference: $this->submit($request, false));
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        $this->validateRequest($request);
        $task = $this->status($request, $reference, Operation::Retrieve, false);

        return match (data_get($task, 'status')) {
            'queued', 'running' => null,
            'completed' => $this->map($request, $task),
            'failed' => throw new DriverException(
                'valyu_research.failed',
                'Valyu could not complete the research report.',
                false,
            ),
            'cancelled' => throw new DriverException(
                'valyu_research.cancelled',
                'The Valyu research report was cancelled.',
                false,
            ),
            'awaiting_input', 'paused' => throw new DriverException(
                'valyu_research.interaction_required',
                'Valyu requested unsupported human interaction.',
                false,
            ),
            default => throw $this->invalidResponse(),
        };
    }

    private function validateRequest(DriverRequest|RecoveryRequest $request): void
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
        $rules = [
            'options' => ['array:mode,research_strategy,report_format,search,urls,output_formats,request_timeout_seconds'],
            ...$this->prefixedRules(),
        ];
        if ($request instanceof DriverRequest) {
            $rules['query'] = ['required', 'string', 'max:25000'];
        }
        $validator = Validator::make(
            ['query' => $request instanceof DriverRequest ? $request->prompt : null, 'options' => $options],
            $rules,
        );
        $validator->after(function (LaravelValidator $validator) use ($options): void {
            $strategy = $options['research_strategy'] ?? null;
            $format = $options['report_format'] ?? null;
            if ((is_string($strategy) ? mb_strlen($strategy) : 0) + (is_string($format) ? mb_strlen($format) : 0) > 15_000) {
                $validator->errors()->add('options.report_format', 'Research strategy and report format are too long.');
            }

            if (! in_array($options['output_formats'] ?? self::DEFAULT_OUTPUT_FORMATS, self::OUTPUT_FORMATS, true)) {
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
        if ($request instanceof DriverRequest && trim($request->prompt) === '') {
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

    private function submit(DriverRequest $request, bool $enforcePostResponseDeadline = true): string
    {
        try {
            $response = $this->send(
                $request,
                'post',
                self::ENDPOINT,
                $this->payload($request),
                Operation::Submit,
            );
        } catch (ConnectionException) {
            throw new DriverException(
                'valyu_research.submit_connection',
                'The Valyu paid research submission did not return a response and will not be retried automatically.',
                false,
            );
        }

        if ($response->status() !== 202) {
            throw $this->submissionFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->ambiguousSubmission();
        }
        $taskId = ValyuSupport::providerReference(data_get($json, 'deepresearch_id'));
        if ($taskId === null) {
            throw $this->ambiguousSubmission();
        }
        if (! in_array(data_get($json, 'status'), ['queued', 'running'], true)
            || data_get($json, 'mode') !== $this->mode($request)
            || ValyuSupport::timestamp(data_get($json, 'created_at')) === null) {
            $this->cancelBestEffort($request, $taskId);

            throw $this->ambiguousSubmission();
        }

        if ($enforcePostResponseDeadline && CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            $this->cancelBestEffort($request, $taskId);

            throw new DriverException(
                'valyu_research.deadline_exceeded',
                'Valyu did not accept the research request before the research deadline.',
                false,
            );
        }

        return $taskId;
    }

    /** @return array<array-key, mixed> */
    private function status(
        DriverRequest|RecoveryRequest $request,
        string $taskId,
        Operation $operation = Operation::Poll,
        bool $retryTransient = true,
    ): array {
        while (true) {
            try {
                $response = $this->send(
                    $request,
                    'get',
                    self::ENDPOINT.'/'.rawurlencode($taskId).'/status',
                    [],
                    $operation,
                );
            } catch (ConnectionException) {
                if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                    throw $this->deadlineExceeded();
                }

                if (! $retryTransient) {
                    throw new DriverException(
                        'valyu_research.connection',
                        'Valyu could not be reached while retrieving the accepted research task.',
                        false,
                    );
                }

                $this->waitBeforePolling($request);

                continue;
            }

            $this->remainingSeconds($request, 'Valyu did not complete before the research deadline.');
            if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
                if (! $retryTransient) {
                    throw $this->httpFailure($response);
                }

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
            'mode' => $this->mode($request),
            'output_formats' => $options['output_formats'] ?? self::DEFAULT_OUTPUT_FORMATS,
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
    private function map(DriverRequest|RecoveryRequest $request, array $task): ResearchResult
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
        if ($mode !== $this->mode($request)) {
            throw $this->invalidResponse();
        }
        $privateValues = [
            (string) $request->profile->credential,
            ValyuSupport::string(data_get($task, 'deepresearch_id')) ?? '',
        ];

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
                    'artifacts' => $this->artifacts($task, $privateValues),
                ],
            ],
        );

        return TerminalRedaction::guard($result, $privateValues, $this->invalidResponse());
    }

    private function mode(DriverRequest|RecoveryRequest $request): string
    {
        $mode = $request->profile->options['mode'] ?? 'standard';

        return is_string($mode) ? $mode : 'standard';
    }

    /**
     * @param  array<array-key, mixed>  $task
     * @param  list<string>  $privateValues
     * @return list<array<string, mixed>>
     */
    private function artifacts(array $task, array $privateValues): array
    {
        $artifacts = [];
        if (ValyuSupport::string(data_get($task, 'pdf_url')) !== null) {
            $artifacts[] = ValyuSupport::pdfArtifact(data_get($task, 'pdf_url'), $privateValues);
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
    private function send(
        DriverRequest|RecoveryRequest $request,
        string $method,
        string $url,
        array $payload = [],
        Operation $operation = Operation::Inline,
    ): Response {
        $remaining = $this->remainingSeconds($request, 'Valyu did not complete before the research deadline.');
        $pending = Http::withHeaders(['X-API-Key' => (string) $request->profile->credential])
            ->acceptJson()
            ->asJson()
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
            ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

        try {
            $response = $method === 'post' ? $pending->post($url, $payload) : $pending->get($url);
        } catch (ConnectionException $exception) {
            $request->captureNoResponse($operation);

            throw $exception;
        }

        $request->captureResponse($response, $operation, ValyuSupport::withoutPdfUrl($response->body()));

        return $response;
    }

    private function waitBeforePolling(DriverRequest|RecoveryRequest $request): void
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
            $response = Http::withHeaders(['X-API-Key' => (string) $request->profile->credential])
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::CANCELLATION_TIMEOUT_SECONDS)
                ->post(self::ENDPOINT.'/'.rawurlencode($taskId).'/cancel');
            $request->captureResponse($response, Operation::Cancel, ValyuSupport::withoutPdfUrl($response->body()));
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Cancel);
        } catch (Throwable) {
            // The original terminal error remains authoritative.
        }
    }

    private function remainingSeconds(DriverRequest|RecoveryRequest $request, string $message): float
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

    private function submissionFailure(Response $response): DriverException
    {
        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            return $this->ambiguousSubmission();
        }

        return $this->httpFailure($response);
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

    private function ambiguousSubmission(): DriverException
    {
        return new DriverException(
            'valyu_research.ambiguous_submission',
            'Valyu did not safely acknowledge the paid research submission; it was not retried.',
            false,
        );
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('valyu_research.invalid_response', 'Valyu returned an invalid research task response.', false);
    }
}
