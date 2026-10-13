<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Response;
use Jkudish\LaravelAiLibrarium\Collection\CaptureSession;
use Jkudish\LaravelAiLibrarium\Collection\Enums\FailureCategory;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\ResponseRedactor;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Events\ResearchProgressed;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Recovery\ResearchHandleSigner;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Run;
use Jkudish\LaravelAiLibrarium\SourceCatalog;
use Jkudish\LaravelAiLibrarium\StoredRun;
use LogicException;
use Throwable;

/**
 * @phpstan-import-type RunResult from Run
 * @phpstan-import-type RunDocument from Run
 */
final readonly class RunRuntime
{
    public function __construct(
        private LibrariumManager $manager,
        private SourceCatalog $catalog,
        private Container $container,
        private ResponseRedactor $redactor,
        private ResearchHandleSigner $signer,
        private ProfileCompatibility $compatibility,
    ) {}

    public function start(string $id): Run
    {
        $doc = $this->required($id);
        $deadline = CarbonImmutable::now()->addSeconds($this->manager->timeout());
        foreach ($doc->run->toArray()['results'] as $result) {
            if ($result['status'] === 'queued') {
                $this->observe($id, $result['source'], true, $deadline);
            }
        }
        if ($doc->run->toArray()['results'] === []) {
            $this->save(new StoredRun(new Run($this->aggregate($doc->run->toArray())), $doc->state, $doc->files, $doc->version));
        }

        return $this->required($id)->snapshot();
    }

    public function refresh(string $id): Run
    {
        $doc = $this->required($id);
        foreach ($doc->run->toArray()['results'] as $result) {
            if ($result['status'] === 'running') {
                $this->observe($id, $result['source'], false);
            }
        }

        return $this->required($id)->snapshot();
    }

    public function failInterrupted(string $id): void
    {
        $doc = $this->required($id);
        $document = $doc->run->toArray();
        foreach ($document['results'] as &$result) {
            // Submitted tasks stay recoverable after a queue timeout.
            if (in_array($result['status'], ['queued', 'running'], true) && data_get($doc->state, 'handles.'.$result['source']) === null) {
                $result['status'] = 'failed';
                $result['error'] = ['code' => 'interrupted', 'message' => 'The calling process was interrupted before a durable handle was saved.', 'retryable' => false];
            }
        }
        unset($result);
        $this->save(new StoredRun(new Run($this->aggregate($document)), $doc->state, $doc->files, $doc->version));
    }

    private function required(string $id): StoredRun
    {
        return $this->manager->store()->find($id) ?? throw new LogicException('Run not found.');
    }

    private function observe(string $id, string $source, bool $start, ?CarbonImmutable $deadline = null): void
    {
        $doc = $this->required($id);
        $result = $doc->run->result($source);
        if ($result === null || ! in_array($result['status'], ['queued', 'running'], true)) {
            return;
        }
        $now = CarbonImmutable::now();
        $handleValue = data_get($doc->state, 'handles.'.$source);
        if ($result['status'] === 'running' && $handleValue === null) {
            $lease = data_get($doc->state, 'leases.'.$source);
            if (is_string($lease) && $now->lessThan(CarbonImmutable::parse($lease))) {
                return;
            }
            $result['status'] = 'failed';
            $result['error'] = ['code' => 'interrupted', 'message' => 'The calling process was interrupted.', 'retryable' => false];
            $this->replace($doc, $result);

            return;
        }
        if (! $start && $handleValue === null) {
            return;
        }
        /** @var array{options: array<string, array<string, mixed>>} $requestState */
        $requestState = $doc->state['request'] ?? ['options' => []];
        $document = $doc->run->toArray();
        $profile = $this->catalog->profile($source, $document['depth'], $requestState['options'][$source] ?? []);
        $driver = $this->container->make($profile->driver);
        if (! $driver instanceof Driver) {
            throw new LogicException('Invalid source driver.');
        }
        $deadline ??= $now->addSeconds($this->manager->timeout());
        $session = new CaptureSession($now);
        $policy = $this->redactor->prepareProfiles(collect([$profile]));
        $capture = function (Response $response, Operation $operation, ?string $body) use ($session, $profile): void {
            $session->capture($profile, $response, $operation, $body);
        };
        $noResponse = function (Operation $operation) use ($session, $profile): void {
            $session->noResponse($profile, $operation);
        };
        $handle = null;
        if ($handleValue !== null) {
            if (! is_array($handleValue) || ! $driver instanceof DurableDriver) {
                throw new LogicException('Invalid stored research handle.');
            }
            $handle = ResearchHandle::fromArray($handleValue);
            $this->signer->verify($handle, $profile);
            if ($handle->requestId !== $id) {
                throw new LogicException('The stored handle belongs to another Run.');
            }
            $request = new RecoveryRequest($id, $profile, $profile, $deadline, $capture, $noResponse, $handle->submittedUsage);
        } else {
            if ($document['depth'] === 'deep' && ! $driver instanceof DurableDriver) {
                throw new LogicException('Deep source drivers must implement DurableDriver.');
            }
            if ($driver instanceof DurableDriver) {
                $this->signer->assertIssuable($profile, $id);
            }
            if (! $this->admit($doc, $result)) {
                return;
            }
            $result['status'] = 'running';
            $result['started_at'] = $now->utc()->toIso8601ZuluString();
            $state = $doc->state;
            $state['leases'] = array_replace(is_array($state['leases'] ?? null) ? $state['leases'] : [], [$source => $deadline->utc()->toIso8601ZuluString()]);
            try {
                $doc = $this->replace($doc, $result, $state);
            } catch (RunStoreConflict) {
                return; // Another writer claimed the call. Never submit twice.
            }
            $request = new DriverRequest($id, $profile, $profile, $profile->renderPrompt($document['question']), $deadline,
                static function (string $message, string $profileId) use ($id): void {
                    ResearchProgressed::dispatch($id, $message, CarbonImmutable::now(), $profileId);
                }, $capture, $noResponse, function () use ($session): void {
                    $session->markPartial();
                });
        }
        $state = $doc->state;
        $files = $doc->files;
        ResearchProgressed::dispatch($id, ($handle === null ? 'Starting ' : 'Observing ').$source.'.', CarbonImmutable::now(), $profile->id);
        try {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw new DriverException('librarium.timeout', 'The shared research deadline was reached.', false);
            }
            if ($handle !== null) {
                $terminal = $driver->retrieveDurable($request, $handle->reference);
            } elseif ($driver instanceof DurableDriver) {
                $submission = $driver->submitDurable($request);
                $handle = $this->signer->issue($profile, $id, $submission->reference, CarbonImmutable::now(), $submission->usage);
                data_set($state, 'handles.'.$source, $handle->toArray());
                $result['cost']['reported'] = $submission->usage?->currency === 'USD' ? $submission->usage->actualCost : null;
                $terminal = null;
            } elseif ($request instanceof DriverRequest) {
                $terminal = $driver->run($request);
            } else {
                throw new LogicException('Invalid research operation.');
            }
            if ($terminal !== null) {
                $terminal = TerminalRedaction::guard($terminal, [(string) $profile->credential, $handle->reference ?? ''], taskHandle: $handle?->reference);
                if (CarbonImmutable::now()->isAfter($deadline)) {
                    throw new DriverException('librarium.timeout', 'The shared research deadline was reached.', false);
                }
                if ((! $profile->allowProviderRouting && $terminal->provider !== $profile->provider) || ! $this->compatibility->acceptsResult($profile, $terminal)) {
                    throw new DriverException('librarium.invalid_result', 'The driver returned a result outside its source binding.', false);
                }
                $result = $this->terminal($result, $terminal, $profile->resultKind === ResultKind::SearchResults);
                data_forget($state, ['handles.'.$source, 'leases.'.$source]);
                if ($result['answer'] !== null) {
                    $files['answers/'.$source.'.md'] = $result['answer'];
                }
            }
        } catch (DriverException $exception) {
            $session->failure($profile, $exception, $request instanceof RecoveryRequest ? Operation::Retrieve : ($driver instanceof DurableDriver ? Operation::Submit : Operation::Inline));
            $retryable = $handle !== null && in_array($session->lastDiagnostic()?->category, [FailureCategory::Temporary, FailureCategory::RateLimit], true);
            $result['status'] = $retryable ? 'running' : 'failed';
            $result['error'] = $retryable ? null : ['code' => $exception->errorCode, 'message' => 'The source operation failed. See diagnostics for its error code.', 'retryable' => false];
            data_forget($state, 'leases.'.$source);
        } catch (Throwable $exception) {
            $result['status'] = $handle === null ? 'failed' : 'running';
            $result['error'] = $handle === null ? ['code' => 'interrupted', 'message' => 'The source operation was interrupted.', 'retryable' => false] : null;
            data_forget($state, 'leases.'.$source);
            report($exception);
        }
        /** @var array<string, mixed> $state */
        $existingEvidence = isset($files['evidence/'.$source.'.json']) ? json_decode($files['evidence/'.$source.'.json'], true, 512, JSON_THROW_ON_ERROR) : [];
        $evidence = $this->redactor->redactResponses($session->responses(), $policy)->map(fn ($response): array => $response->toArray())->all();
        $files['evidence/'.$source.'.json'] = json_encode(array_merge(is_array($existingEvidence) ? $existingEvidence : [], $evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $diagnostics = $state['diagnostics'] ?? [];
        $state['diagnostics'] = array_merge(is_array($diagnostics) ? $diagnostics : [], $session->diagnostics()->map(fn ($diagnostic): array => $diagnostic->toArray())->all());
        // Retrieval may race another observation. Reapply only this source's
        // result to the latest version; terminal observations never regress.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $this->replace($doc, $result, $state, $files);

                return;
            } catch (RunStoreConflict) {
                $doc = $this->required($id);
                if (in_array($doc->run->result($source)['status'] ?? null, ['done', 'failed'], true)) {
                    return;
                }
                $latestState = $doc->state;
                foreach (['handles', 'leases'] as $field) {
                    $value = data_get($state, $field.'.'.$source);
                    if ($value !== null) {
                        data_set($latestState, $field.'.'.$source, $value);
                    } else {
                        data_forget($latestState, $field.'.'.$source);
                    }
                }
                /** @var array<string, mixed> $latestState */
                $latestDiagnostics = $doc->state['diagnostics'] ?? [];
                $latestState['diagnostics'] = array_merge(is_array($latestDiagnostics) ? $latestDiagnostics : [], $session->diagnostics()->map(fn ($diagnostic): array => $diagnostic->toArray())->all());
                $state = $latestState;
                $latestEvidence = isset($doc->files['evidence/'.$source.'.json']) ? json_decode($doc->files['evidence/'.$source.'.json'], true, 512, JSON_THROW_ON_ERROR) : [];
                $files = array_replace($doc->files, array_intersect_key($files, ['answers/'.$source.'.md' => true]));
                $files['evidence/'.$source.'.json'] = json_encode(array_merge(is_array($latestEvidence) ? $latestEvidence : [], $evidence), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        throw new RunStoreConflict('The Run remained busy after observation.');
    }

    /** @param RunResult $result */
    private function admit(StoredRun $doc, array $result): bool
    {
        $max = $doc->run->toArray()['cost']['max'];
        if ($max === null) {
            return true;
        }
        $reserved = BigDecimal::zero();
        foreach ($doc->run->toArray()['results'] as $item) {
            $reported = BigDecimal::of($item['cost']['reported'] ?? '0');
            $reservation = in_array($item['status'], ['queued', 'running'], true) ? BigDecimal::of($item['cost']['estimated'] ?? '0') : BigDecimal::zero();
            $reserved = $reserved->plus(BigDecimal::max($reported, $reservation));
        }
        if ($reserved->isLessThanOrEqualTo($max)) {
            return true;
        }
        $document = $doc->run->toArray();
        $document['results'] = array_values(array_filter($document['results'], fn (array $item): bool => $item['source'] !== $result['source']));
        $document['skipped'][] = ['source' => $result['source'], 'reason' => 'over_budget'];
        $order = array_flip(array_keys($this->catalog->entries()));
        usort($document['skipped'], fn (array $a, array $b): int => $order[$a['source']] <=> $order[$b['source']]);
        $this->save(new StoredRun(new Run($this->aggregate($document)), $doc->state, $doc->files, $doc->version));

        return false;
    }

    /**
     * @param  RunResult  $result
     * @param  array<string, mixed>|null  $state
     * @param  array<string, string>|null  $files
     */
    private function replace(StoredRun $doc, array $result, ?array $state = null, ?array $files = null): StoredRun
    {
        $document = $doc->run->toArray();
        foreach ($document['results'] as $index => $item) {
            if ($item['source'] === $result['source']) {
                $document['results'][$index] = $result;
            }
        }

        return $this->save(new StoredRun(new Run($this->aggregate($document)), $state ?? $doc->state, $files ?? $doc->files, $doc->version));
    }

    private function save(StoredRun $doc): StoredRun
    {
        $previous = $this->required($doc->run->id())->run->status();
        $saved = $doc->withVersion($this->manager->store()->save($doc, $doc->version));
        if ($previous === 'running' && $saved->run->status() !== 'running') {
            ResearchCompleted::dispatch($saved->snapshot());
        }

        return $saved;
    }

    /**
     * @param  RunResult  $result
     * @return RunResult
     */
    private function terminal(array $result, ResearchResult $terminal, bool $searchOnly): array
    {
        $result['status'] = 'done';
        $result['provenance']['provider'] = $terminal->provider;
        $result['provenance']['model'] = $terminal->model ?? $result['provenance']['model'];
        $result['answer'] = ! $searchOnly && is_string($terminal->content) ? $terminal->content : null;
        $result['citations'] = [];
        foreach ($terminal->citations as $citation) {
            if ($citation->source->url !== null) {
                $result['citations'][] = ['url' => $citation->source->url, 'title' => $citation->source->title];
            }
        }
        $result['cost']['reported'] = $terminal->usage?->currency === 'USD' ? $terminal->usage->actualCost : null;
        $result['duration_ms'] = $result['started_at'] === null ? null : max(0, (int) CarbonImmutable::parse($result['started_at'])->diffInMilliseconds($terminal->completedAt));

        return $result;
    }

    /**
     * @param  RunDocument  $document
     * @return RunDocument
     */
    private function aggregate(array $document): array
    {
        $pending = false;
        $succeeded = 0;
        $reported = BigDecimal::zero();
        $known = false;
        $complete = true;
        foreach ($document['results'] as $result) {
            $pending = $pending || in_array($result['status'], ['queued', 'running'], true);
            $succeeded += $result['status'] === 'done' ? 1 : 0;
            $complete = $complete && $result['cost']['reported'] !== null;
            if ($result['cost']['reported'] !== null) {
                $known = true;
                $reported = $reported->plus($result['cost']['reported']);
            }
        }
        $document['status'] = $pending ? 'running' : ($succeeded === count($document['results']) ? 'done' : ($succeeded > 0 ? 'partial' : 'failed'));
        $document['finished_at'] = $pending ? null : CarbonImmutable::now()->utc()->toIso8601ZuluString();
        $document['cost']['reported'] = $known ? (string) $reported : null;
        $document['cost']['complete'] = $complete;

        return $document;
    }
}
