<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Collection\CaptureSession;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\SensitiveCollection;
use Jkudish\LaravelAiLibrarium\Contracts\CollectsResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Events\ResearchCompleted;
use Jkudish\LaravelAiLibrarium\Events\ResearchFailed;
use Jkudish\LaravelAiLibrarium\Events\ResearchProgressed;
use Jkudish\LaravelAiLibrarium\Events\ResearchStarted;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\QueuedResearchResponse;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\ResearchStatus;
use Jkudish\LaravelAiLibrarium\ResearchStatusStore;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchError;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use LogicException;
use Throwable;

final readonly class ResearchRuntime implements CollectsResearch, ExecutesResearch
{
    private const string GENERATOR = 'jkudish/laravel-ai-librarium';

    public function __construct(
        private Container $container,
        private ResearchStatusStore $statuses,
        private ProfileCompatibility $compatibility,
    ) {}

    public function run(ResearchPlan $plan): ResearchResponse
    {
        return $this->execute($plan, null, CarbonImmutable::now());
    }

    public function collect(ResearchPlan $plan): SensitiveCollection
    {
        $startedAt = CarbonImmutable::now();
        $capture = new CaptureSession($startedAt);

        return $capture->finish($this->execute($plan, $capture, $startedAt));
    }

    private function execute(
        ResearchPlan $plan,
        ?CaptureSession $capture,
        CarbonImmutable $startedAt,
    ): ResearchResponse {
        $this->ensureNotTerminal($plan->requestId);
        $activeProfileId = null;

        try {
            $deadline = $startedAt->addSeconds($plan->timeout);
            $this->started($plan->requestId, $startedAt);

            /** @var list<ResearchResult> $resultItems */
            $resultItems = [];
            /** @var list<ResearchError> $errorItems */
            $errorItems = [];
            $usedProfiles = [];

            foreach ($plan->preview->profiles as $requestedProfile) {
                $activeProfileId = $requestedProfile->id;
                $outcome = $this->executeSlot(
                    plan: $plan,
                    requestedProfile: $requestedProfile,
                    deadline: $deadline,
                    usedProfiles: $usedProfiles,
                    activeProfileId: $activeProfileId,
                    capture: $capture,
                );

                if ($outcome instanceof ResearchResult) {
                    $resultItems[] = $outcome;
                } else {
                    $errorItems[] = $outcome;
                }
            }

            $results = collect($resultItems);
            $errors = collect($errorItems);

            $response = new ResearchResponse(
                generator: self::GENERATOR,
                generatorVersion: $this->generatorVersion(),
                requestId: $plan->requestId,
                status: $this->responseStatus($results, $errors),
                completedAt: CarbonImmutable::now(),
                results: $results,
                errors: $errors,
                usage: null,
            );
            $response->toArray();
            if (! $this->completed($response)) {
                throw new LogicException('The research request has already reached a terminal state.');
            }

            return $response;
        } catch (Throwable $exception) {
            $this->failed($plan->requestId, $exception, $activeProfileId);

            throw $exception;
        }
    }

    public function queue(ResearchPlan $plan): QueuedResearchResponse
    {
        $this->ensureNotTerminal($plan->requestId);
        $this->storeStatus(
            $plan->requestId,
            new ResearchStatus(ResearchState::Queued, 'Research is queued.', CarbonImmutable::now()),
        );

        return new QueuedResearchResponse($plan->requestId, RunResearch::dispatch($plan));
    }

    private function ensureNotTerminal(string $requestId): void
    {
        try {
            $status = $this->statuses->get($requestId);
        } catch (Throwable $exception) {
            // Status is best effort, not a durable execution claim.
            report($exception);

            return;
        }

        if (in_array($status?->state, [ResearchState::Completed, ResearchState::Failed], true)) {
            throw new LogicException('The research request has already reached a terminal state.');
        }
    }

    /**
     * @param  array<string, true>  $usedProfiles
     */
    private function executeSlot(
        ResearchPlan $plan,
        Profile $requestedProfile,
        CarbonImmutable $deadline,
        array &$usedProfiles,
        string &$activeProfileId,
        ?CaptureSession $capture,
    ): ResearchResult|ResearchError {
        $profile = $requestedProfile;
        $fallbackReason = null;

        while (true) {
            $activeProfileId = $profile->id;
            $usedProfiles[$profile->id] = true;
            $this->progressed($plan->requestId, "Running Profile [{$profile->id}].", $profile->id);

            try {
                $result = $this->executeProfile($plan, $requestedProfile, $profile, $deadline, $capture);

                return $this->resultFor($result, $requestedProfile, $profile, $fallbackReason);
            } catch (DriverException $exception) {
                $capture?->failure($profile, $exception);

                if ($exception->fallbackAllowed) {
                    $fallback = $this->nextFallback($plan, $requestedProfile, $usedProfiles);
                    if ($fallback !== null) {
                        $fallbackReason ??= $exception->getMessage();
                        $profile = $fallback;

                        continue;
                    }
                }

                return new ResearchError(
                    code: $exception->errorCode,
                    message: $exception->getMessage(),
                    profile: $profile->id,
                );
            }
        }
    }

    private function executeProfile(
        ResearchPlan $plan,
        Profile $requestedProfile,
        Profile $profile,
        CarbonImmutable $deadline,
        ?CaptureSession $capture,
    ): ResearchResult {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
            throw new DriverException(
                errorCode: 'librarium.timeout',
                message: 'The shared research deadline was reached.',
                fallbackAllowed: false,
            );
        }

        $driver = $this->container->make($profile->driver);
        if (! $driver instanceof Driver) {
            throw new LogicException("Research Driver [{$profile->driver}] is invalid.");
        }

        $prompt = $plan->preview->prompts->get($profile->id);
        if (! is_string($prompt)) {
            throw new LogicException("The rendered prompt for Profile [{$profile->id}] is missing.");
        }

        $attempts = $capture?->attemptCount();
        try {
            $result = $driver->run(new DriverRequest(
                requestId: $plan->requestId,
                requestedProfile: $requestedProfile,
                profile: $profile,
                prompt: $prompt,
                deadline: $deadline,
                progressCallback: fn (string $message, string $profileId): null => $this->progressed(
                    $plan->requestId,
                    $message,
                    $profileId,
                ),
                responseCallback: $capture === null
                    ? null
                    : fn (Response $response, Operation $operation, ?string $body): null => $this->captureResponse(
                        $capture,
                        $profile,
                        $response,
                        $operation,
                        $body,
                    ),
                noResponseCallback: $capture === null
                    ? null
                    : fn (Operation $operation): null => $this->captureNoResponse($capture, $profile, $operation),
                partialCaptureCallback: $capture === null
                    ? null
                    : fn (): null => $this->markCapturePartial($capture),
            ));
        } finally {
            if ($capture !== null && $attempts === $capture->attemptCount()) {
                $capture->markUnavailable();
            }
        }
        $result = TerminalRedaction::guard($result, [(string) $profile->credential]);

        if (CarbonImmutable::now()->isAfter($deadline)) {
            throw new DriverException(
                errorCode: 'librarium.timeout',
                message: 'The shared research deadline was reached.',
                fallbackAllowed: false,
            );
        }

        return $result;
    }

    private function captureResponse(
        CaptureSession $capture,
        Profile $profile,
        Response $response,
        Operation $operation,
        ?string $body,
    ): null {
        $capture->capture($profile, $response, $operation, $body);

        return null;
    }

    private function captureNoResponse(CaptureSession $capture, Profile $profile, Operation $operation): null
    {
        $capture->noResponse($profile, $operation);

        return null;
    }

    private function markCapturePartial(CaptureSession $capture): null
    {
        $capture->markPartial();

        return null;
    }

    /** @param array<string, true> $usedProfiles */
    private function nextFallback(
        ResearchPlan $plan,
        Profile $requestedProfile,
        array $usedProfiles,
    ): ?Profile {
        return $plan->preview->fallbacks->first(
            fn (Profile $fallback): bool => ! isset($usedProfiles[$fallback->id])
                && $this->compatibility->allows($requestedProfile, $fallback),
        );
    }

    private function resultFor(
        ResearchResult $result,
        Profile $requestedProfile,
        Profile $profile,
        ?string $fallbackReason,
    ): ResearchResult {
        if ($result->provider !== $profile->provider
            && ! $profile->allowProviderRouting) {
            throw new LogicException("Research Driver [{$profile->driver}] returned an unexpected provider identity for Profile [{$profile->id}].");
        }

        if (! $this->compatibility->acceptsResult($profile, $result)) {
            throw new LogicException("Research Driver [{$profile->driver}] returned a result outside Profile [{$profile->id}] semantics.");
        }

        return new ResearchResult(
            id: $result->id,
            contentFormat: $result->contentFormat,
            content: $result->content,
            requestedProfile: $requestedProfile->id,
            provider: $result->provider,
            profile: $profile->id,
            provenance: $result->provenance,
            citations: $result->citations,
            completedAt: $result->completedAt,
            model: $result->model ?? $profile->model,
            fallbackReason: $fallbackReason,
            usage: $result->usage,
            providerMeta: $result->providerMeta,
        );
    }

    /**
     * @param  Collection<int, ResearchResult>  $results
     * @param  Collection<int, ResearchError>  $errors
     */
    private function responseStatus(Collection $results, Collection $errors): ResponseStatus
    {
        if ($errors->isEmpty()) {
            return ResponseStatus::Succeeded;
        }

        return $results->isEmpty() ? ResponseStatus::Failed : ResponseStatus::Partial;
    }

    private function started(string $requestId, CarbonImmutable $occurredAt): void
    {
        $this->storeStatus(
            $requestId,
            new ResearchStatus(ResearchState::Running, 'Research started.', $occurredAt),
        );
        ResearchStarted::dispatch($requestId, $occurredAt);
    }

    private function progressed(string $requestId, string $message, ?string $profileId = null): null
    {
        $occurredAt = CarbonImmutable::now();
        $this->storeStatus(
            $requestId,
            new ResearchStatus(ResearchState::Running, $message, $occurredAt),
        );
        ResearchProgressed::dispatch($requestId, $message, $occurredAt, $profileId);

        return null;
    }

    private function completed(ResearchResponse $response): bool
    {
        if (! $this->transitionToTerminal(
            $response->requestId,
            new ResearchStatus(ResearchState::Completed, 'Research completed.', $response->completedAt),
        )) {
            return false;
        }

        ResearchCompleted::dispatch($response);

        return true;
    }

    private function failed(string $requestId, Throwable $exception, ?string $profileId): void
    {
        $occurredAt = CarbonImmutable::now();
        if (! $this->transitionToTerminal(
            $requestId,
            new ResearchStatus(ResearchState::Failed, 'Research failed unexpectedly.', $occurredAt),
        )) {
            return;
        }

        ResearchFailed::dispatch($requestId, $exception, $occurredAt, $profileId);
    }

    private function storeStatus(string $requestId, ResearchStatus $status): void
    {
        try {
            $this->statuses->put($requestId, $status);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function transitionToTerminal(string $requestId, ResearchStatus $status): bool
    {
        try {
            return $this->statuses->transitionToTerminal($requestId, $status);
        } catch (Throwable $exception) {
            report($exception);

            return true;
        }
    }

    private function generatorVersion(): string
    {
        $version = InstalledVersions::isInstalled(self::GENERATOR)
            ? InstalledVersions::getPrettyVersion(self::GENERATOR)
            : null;

        if (is_string($version) && preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)$/', $version, $matches) === 1) {
            return $matches[1];
        }

        return '0.1.0-dev';
    }
}
