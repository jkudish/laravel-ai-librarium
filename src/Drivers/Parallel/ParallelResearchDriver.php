<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Parallel;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DeclaresDeadline;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profile;
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
use JsonException;
use stdClass;

/** Runs one durable Parallel Task API deep-research job to completion. */
final readonly class ParallelResearchDriver implements DeclaresDeadline, DurableDriver
{
    private const string ENDPOINT = 'https://api.parallel.ai/v1/tasks/runs';

    private const float POLL_INTERVAL_SECONDS = 1.0;

    private const string DEFAULT_OUTPUT_DESCRIPTION = 'A thorough cited research report that directly answers the request.';

    /**
     * Every processor Parallel lists (docs.parallel.ai, checked 2026-10-11),
     * mapped to its default polling deadline in seconds. Processors through
     * pro finish within 10 minutes and get 30; ultra and above get 1.5x the
     * documented upper latency, rounded up to 15 minutes, because queue time
     * is extra. A Profile's request_timeout_seconds replaces it; the shared
     * research deadline always caps it.
     *
     * @var array<string, int>
     */
    public const array PROCESSOR_DEADLINE_SECONDS = [
        'lite' => 1800,
        'base' => 1800,
        'core' => 1800,
        'core2x' => 1800,
        'pro' => 1800,
        'ultra' => 2700,
        'ultra2x' => 4500,
        'ultra4x' => 8100,
        'ultra8x' => 10800,
    ];

    public function __construct(private ParallelTransport $transport) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return ParallelOptions::researchRules();
    }

    public static function deadlineSeconds(Profile $profile): ?int
    {
        $timeout = $profile->options['request_timeout_seconds'] ?? null;

        return is_int($timeout) ? $timeout : self::PROCESSOR_DEADLINE_SECONDS[$profile->model ?? ''] ?? null;
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $processor = $this->validateProfile($request);
        $options = ParallelOptions::research($request->profile->options);
        $request = $request->withinSeconds(self::deadlineSeconds($request->profile) ?? self::PROCESSOR_DEADLINE_SECONDS['pro']);
        $runId = $this->submit($request, $processor, $options);
        $request->progress('Parallel is researching in the background.');

        while (true) {
            $this->wait($request);
            $status = $this->status($request, $runId, $processor);
            if ($status === null) {
                continue;
            }
            if ($status === 'completed') {
                break;
            }
            if ($status === 'failed') {
                throw new DriverException('parallel.research_failed', 'Parallel could not complete the Research run.', false);
            }
            if ($status === 'cancelled') {
                throw new DriverException('parallel.cancelled', 'The Parallel Research run was cancelled.', false);
            }
            if ($status === 'action_required') {
                throw new DriverException('parallel.action_required', 'Parallel requested unsupported human interaction.', false);
            }
        }

        while (true) {
            $result = $this->retrieve($request, $runId, $processor);
            if ($result !== null) {
                return $result;
            }

            $this->wait($request);
        }
    }

    public function submitDurable(DriverRequest $request): DurableSubmission
    {
        $processor = $this->validateProfile($request);

        return new DurableSubmission(
            reference: $this->submit($request, $processor, ParallelOptions::research($request->profile->options), false),
        );
    }

    public function retrieveDurable(RecoveryRequest $request, string $reference): ?ResearchResult
    {
        $processor = $this->validateProfile($request);
        $status = $this->status($request, $reference, $processor, false, Operation::Retrieve);
        if ($status === null) {
            throw $this->invalidResponse();
        }

        return match ($status) {
            'queued', 'running', 'cancelling' => null,
            'completed' => $this->retrieve($request, $reference, $processor, false)
                ?? throw $this->invalidResponse(),
            'failed' => throw new DriverException(
                'parallel.research_failed',
                'Parallel could not complete the Research run.',
                false,
            ),
            'cancelled' => throw new DriverException(
                'parallel.cancelled',
                'The Parallel Research run was cancelled.',
                false,
            ),
            'action_required' => throw new DriverException(
                'parallel.action_required',
                'Parallel requested unsupported human interaction.',
                false,
            ),
            default => throw $this->invalidResponse(),
        };
    }

    private function validateProfile(DriverRequest|RecoveryRequest $request): string
    {
        $profile = $request->profile;
        if ($profile->id !== 'parallel-research'
            || $profile->provider !== 'parallel'
            || ! is_string($profile->model)
            || ! array_key_exists($profile->model, self::PROCESSOR_DEADLINE_SECONDS)
            || $profile->resultKind !== ResultKind::ResearchReport
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ResearchAgent]
        ) {
            throw new DriverException(
                'parallel.invalid_profile',
                'The Parallel Research Profile has incompatible identity, processor, or provenance semantics.',
                false,
            );
        }
        if (blank($profile->credential)) {
            throw new DriverException('parallel.authentication', 'The Parallel provider is not configured.');
        }
        if ($request instanceof DriverRequest
            && (trim($request->prompt) === '' || mb_strlen($request->prompt) > 15_000)) {
            throw new DriverException('parallel.invalid_request', 'The Parallel Research input is invalid.', false);
        }
        $this->transport->remainingSeconds($request);

        return $profile->model;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function submit(
        DriverRequest $request,
        string $processor,
        array $options,
        bool $enforcePostResponseDeadline = true,
    ): string {
        $sourcePolicy = array_filter([
            'include_domains' => $options['include_domains'] ?? null,
            'exclude_domains' => $options['exclude_domains'] ?? null,
            'after_date' => $options['after_date'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
        $outputSchema = $options['output_schema'] ?? self::DEFAULT_OUTPUT_DESCRIPTION;
        $response = $this->transport->post($request, self::ENDPOINT, array_filter([
            'input' => $request->prompt,
            'processor' => $processor,
            'metadata' => $options['metadata'] ?? null,
            'memory_scope_key' => $options['memory_scope_key'] ?? null,
            'task_spec' => ['output_schema' => is_array($outputSchema)
                ? ['type' => 'json', 'json_schema' => $outputSchema]
                : ['type' => 'text', 'description' => $outputSchema]],
            'source_policy' => $sourcePolicy === [] ? null : $sourcePolicy,
            'advanced_settings' => isset($options['location']) ? ['location' => $options['location']] : null,
        ], static fn (mixed $value): bool => $value !== null), true, $enforcePostResponseDeadline);

        if ($response->redirect()) {
            throw new DriverException(
                'parallel.ambiguous_submission',
                'Parallel did not safely acknowledge the Research submission; it was not retried.',
                false,
            );
        }
        if (in_array($response->status(), [408, 429], true) || $response->serverError()) {
            throw new DriverException(
                'parallel.ambiguous_submission',
                'Parallel did not safely reject the Research submission; it was not retried.',
                false,
            );
        }
        if ($response->status() !== 202) {
            throw $this->transport->failure($response, true);
        }

        $json = $this->json($response);
        $runId = $this->runId($json['run_id'] ?? null);
        if ($runId === null || ($json['status'] ?? null) !== 'queued'
            || ($json['processor'] ?? null) !== $processor) {
            throw new DriverException(
                'parallel.ambiguous_submission',
                'Parallel accepted an invalid Research submission response; the submission was not retried.',
                false,
            );
        }

        return $runId;
    }

    private function status(
        DriverRequest|RecoveryRequest $request,
        string $runId,
        string $processor,
        bool $transientAsPending = true,
        Operation $operation = Operation::Poll,
    ): ?string {
        try {
            $response = $this->transport->get($request, self::ENDPOINT.'/'.rawurlencode($runId), $operation);
        } catch (DriverException $exception) {
            if ($exception->errorCode === 'parallel.connection') {
                if (! $transientAsPending) {
                    throw $exception;
                }

                return null;
            }

            throw $exception;
        }
        if ($this->transient($response)) {
            if (! $transientAsPending) {
                throw $this->transport->failure($response, true);
            }

            return null;
        }
        if ($response->failed() || $response->redirect()) {
            throw $this->transport->failure($response, true);
        }

        $json = $this->json($response);
        if ($this->runId($json['run_id'] ?? null) !== $runId || ($json['processor'] ?? null) !== $processor) {
            throw $this->invalidResponse();
        }
        $status = $json['status'] ?? null;
        if (! in_array($status, ['queued', 'action_required', 'running', 'completed', 'failed', 'cancelling', 'cancelled'], true)) {
            throw $this->invalidResponse();
        }

        return $status;
    }

    private function retrieve(
        DriverRequest|RecoveryRequest $request,
        string $runId,
        string $processor,
        bool $transientAsPending = true,
    ): ?ResearchResult {
        $timeout = max(1, min(25, (int) floor($this->transport->remainingSeconds($request))));
        $url = self::ENDPOINT.'/'.rawurlencode($runId).'/result?timeout='.$timeout;
        try {
            $response = $this->transport->get($request, $url, Operation::Retrieve);
        } catch (DriverException $exception) {
            if ($exception->errorCode === 'parallel.connection') {
                if (! $transientAsPending) {
                    throw $exception;
                }

                return null;
            }

            throw $exception;
        }
        if ($this->transient($response)) {
            if (! $transientAsPending) {
                throw $this->transport->failure($response, true);
            }

            return null;
        }
        if ($response->failed() || $response->redirect()) {
            throw $this->transport->failure($response, true);
        }

        return $this->result($request, $response, $runId, $processor);
    }

    private function result(
        DriverRequest|RecoveryRequest $request,
        Response $response,
        string $runId,
        string $processor,
    ): ResearchResult {
        $json = $this->json($response);
        $run = $json['run'] ?? null;
        $output = $json['output'] ?? null;
        $structured = is_array($request->profile->options['output_schema'] ?? null);
        if (! is_array($run) || array_is_list($run) || ! is_array($output) || array_is_list($output)
            || $this->runId($run['run_id'] ?? null) !== $runId
            || ($run['status'] ?? null) !== 'completed'
            || ($run['processor'] ?? null) !== $processor
            || ($output['type'] ?? null) !== ($structured ? 'json' : 'text')
            || ! is_array($output['basis'] ?? null) || ! array_is_list($output['basis'])) {
            throw $this->invalidResponse();
        }
        $content = $structured ? $this->structuredContent($response) : $output['content'] ?? null;
        if (! $content instanceof stdClass && (! is_string($content) || trim($content) === '')) {
            throw $this->invalidResponse();
        }

        [$citations, $basis] = $this->basis($output['basis']);
        $now = CarbonImmutable::now();

        return TerminalRedaction::guard(ResearchResult::make(
            contentFormat: $structured ? ContentFormat::Json : ContentFormat::Markdown,
            content: $content,
            requestedProfile: $request->requestedProfile->id,
            provider: 'parallel',
            profile: 'parallel-research',
            provenance: new ResultProvenance(
                resultKind: ResultKind::ResearchReport,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: $citations,
            completedAt: $now,
            model: $processor,
            providerMeta: (object) ['parallel:research' => [
                'processor' => $processor,
                'execution' => 'background',
                'resumability' => 'durable_provider_task',
                'metering' => ['unit' => 'processor_request', 'count' => 1],
                'basis' => $basis,
            ]],
        ), [(string) $request->profile->credential, $runId], $this->invalidResponse());
    }

    /**
     * @param  list<mixed>  $values
     * @return array{Collection<int, Citation>, list<array{field: string, reasoning: string, confidence: ?string, citations: list<array{url: string, title: ?string, excerpts: list<string>}>}>}
     */
    private function basis(array $values): array
    {
        $citations = [];
        $basis = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_array($value) || array_is_list($value)
                || ($field = $this->str($value['field'] ?? null)) === null
                || ($reasoning = $this->str($value['reasoning'] ?? null)) === null
                || (($value['confidence'] ?? null) !== null && ! is_string($value['confidence']))
                || ! is_array($value['citations'] ?? null) || ! array_is_list($value['citations'])) {
                throw $this->invalidResponse();
            }
            $basisCitations = [];
            foreach ($value['citations'] as $candidate) {
                $excerptValues = is_array($candidate) ? ($candidate['excerpts'] ?? null) : null;
                if (! is_array($candidate) || array_is_list($candidate)
                    || ! is_string($candidate['url'] ?? null)
                    || (($candidate['title'] ?? null) !== null && ! is_string($candidate['title']))
                    || ($excerptValues !== null
                        && (! is_array($excerptValues) || ! array_is_list($excerptValues)
                            || array_any($excerptValues, static fn (mixed $excerpt): bool => ! is_string($excerpt))))) {
                    throw $this->invalidResponse();
                }
                $url = $this->evidenceUrl($candidate['url']);
                if ($url === null) {
                    continue;
                }
                $title = $this->str($candidate['title'] ?? null);
                $excerpts = [];
                foreach ($excerptValues ?? [] as $excerpt) {
                    if (is_string($excerpt) && trim($excerpt) !== '') {
                        $excerpts[] = $excerpt;
                    }
                    if (count($excerpts) === 5) {
                        break;
                    }
                }
                $basisCitations[] = ['url' => $url, 'title' => $title, 'excerpts' => $excerpts];
                if (! isset($seen[$url])) {
                    $seen[$url] = true;
                    $citations[] = Citation::make(
                        derivation: CitationDerivation::ProviderReported,
                        source: new Source(kind: SourceKind::WebPage, url: $url, title: $title),
                        excerpt: isset($excerpts[0]) ? mb_substr($excerpts[0], 0, 500) : null,
                    );
                }
            }
            $basis[] = [
                'field' => $field,
                'reasoning' => $reasoning,
                'confidence' => $this->str($value['confidence'] ?? null),
                'citations' => $basisCitations,
            ];
        }

        return [collect($citations), $basis];
    }

    /** Decodes JSON output as objects so the result keeps the provider's object and list shapes. */
    private function structuredContent(Response $response): ?stdClass
    {
        try {
            $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }
        $content = $decoded instanceof stdClass && ($decoded->output ?? null) instanceof stdClass
            ? $decoded->output->content ?? null
            : null;

        return $content instanceof stdClass && get_object_vars($content) !== [] ? $content : null;
    }

    private function wait(DriverRequest $request): void
    {
        $remaining = $this->transport->remainingSeconds($request);
        Sleep::for(min(self::POLL_INTERVAL_SECONDS, $remaining))->seconds();
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->transport->deadlineExceeded();
        }
    }

    private function transient(Response $response): bool
    {
        return in_array($response->status(), [408, 429], true) || $response->serverError();
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

    private function runId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^trun_[A-Za-z0-9]{1,250}$/D', $value) === 1 ? $value : null;
    }

    private function evidenceUrl(mixed $value): ?string
    {
        return EvidenceUrl::sanitize($value);
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('parallel.invalid_response', 'Parallel returned an invalid Research response.', false);
    }
}
