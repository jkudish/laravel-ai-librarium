<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Recovery;

use Carbon\CarbonImmutable;
use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\CaptureSession;
use Jkudish\LaravelAiLibrarium\Collection\Enums\CaptureCoverage;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Collection\RedactedProviderResponse;
use Jkudish\LaravelAiLibrarium\Collection\RedactionPolicy;
use Jkudish\LaravelAiLibrarium\Collection\ResponseRedactor;
use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\DurableOutcome;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Exceptions\RecoveryException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Execution\ProfileCompatibility;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\ResearchHandle;
use Jkudish\LaravelAiLibrarium\ResearchPlan;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchError;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use LogicException;

final readonly class DurableResearchRuntime implements RecoversResearch
{
    private const string GENERATOR = 'jkudish/laravel-ai-librarium';

    public function __construct(
        private Container $container,
        private ConfigRepository $config,
        private ProfileCatalog $catalog,
        private ProfileCompatibility $compatibility,
        private ResearchHandleSigner $signer,
        private ResponseRedactor $redactor,
    ) {}

    public function submit(ResearchPlan $plan, bool $capture = true): DurableOutcome
    {
        if ($plan->preview->profiles->count() !== 1 || $plan->preview->fallbacks->isNotEmpty()) {
            throw ValidationException::withMessages([
                'selector' => 'Durable submission requires one exact Profile and no fallbacks.',
            ]);
        }

        /** @var Profile $profile */
        $profile = $plan->preview->profiles->first();
        $this->signer->assertIssuable($profile, $plan->requestId);
        $driver = $this->driver($profile);
        $prompt = $plan->preview->prompts->get($profile->id);
        if (! is_string($prompt)) {
            throw new LogicException("The rendered prompt for Profile [{$profile->id}] is missing.");
        }

        $startedAt = CarbonImmutable::now();
        $session = new CaptureSession($startedAt);
        $policy = $capture ? $this->redactor->prepareProfiles(collect([$profile])) : null;
        $request = new DriverRequest(
            requestId: $plan->requestId,
            requestedProfile: $profile,
            profile: $profile,
            prompt: $prompt,
            deadline: $startedAt->addSeconds($plan->timeout),
            progressCallback: static fn (): null => null,
            responseCallback: fn (Response $response, Operation $operation, ?string $body): null => $this->captureResponse(
                $session,
                $profile,
                $response,
                $operation,
                $body,
            ),
            noResponseCallback: fn (Operation $operation): null => $this->captureNoResponse($session, $profile, $operation),
        );

        try {
            $submission = $driver->submitDurable($request);
        } catch (DriverException $exception) {
            throw $this->failure($session, $profile, $exception, Operation::Submit);
        }

        $handle = $this->signer->issue(
            $profile,
            $plan->requestId,
            $submission->reference,
            CarbonImmutable::now(),
            $submission->usage,
        );

        return $this->outcome($handle, null, $session, $policy, $capture);
    }

    public function retrieve(ResearchHandle $handle, bool $capture = true): DurableOutcome
    {
        $available = $this->catalog->find($handle->profile);
        if ($available === null || ! $available->isEligible()) {
            throw ValidationException::withMessages([
                'handle' => 'The research handle Profile is not currently available.',
            ]);
        }

        $profile = $available->profile;
        $this->signer->verify($handle, $profile);
        $driver = $this->driver($profile);
        $startedAt = CarbonImmutable::now();
        $session = new CaptureSession($startedAt);
        $policy = $capture ? $this->redactor->prepareProfiles(collect([$profile])) : null;
        $timeout = $this->config->get('librarium.timeout', 6900);
        if (! is_int($timeout) || $timeout < 1 || $timeout > 86400) {
            throw ValidationException::withMessages(['timeout' => 'The configured timeout must be between 1 and 86400 seconds.']);
        }

        $request = new RecoveryRequest(
            requestId: $handle->requestId,
            requestedProfile: $profile,
            profile: $profile,
            deadline: $startedAt->addSeconds($timeout),
            responseCallback: fn (Response $response, Operation $operation, ?string $body): null => $this->captureResponse(
                $session,
                $profile,
                $response,
                $operation,
                $body,
            ),
            noResponseCallback: fn (Operation $operation): null => $this->captureNoResponse($session, $profile, $operation),
            submittedUsage: $handle->submittedUsage,
        );

        try {
            $result = $driver->retrieveDurable($request, $handle->reference);
            if ($result !== null) {
                $result = TerminalRedaction::guard(
                    $result,
                    [(string) $profile->credential, $handle->reference],
                    new DriverException(
                        'librarium.unsafe_terminal_result',
                        'The provider returned unsafe terminal result content.',
                        false,
                    ),
                );
            }
        } catch (DriverException $exception) {
            throw $this->failure($session, $profile, $exception, Operation::Retrieve);
        }

        if ($result !== null && ! $this->compatibility->acceptsResult($profile, $result)) {
            throw new LogicException("Research Driver [{$profile->driver}] returned a result outside Profile [{$profile->id}] semantics.");
        }

        return $this->outcome(
            $handle,
            $result === null ? null : $this->response($handle, $result),
            $session,
            $policy,
            $capture,
        );
    }

    private function driver(Profile $profile): DurableDriver
    {
        $driver = $this->container->make($profile->driver);
        if (! $driver instanceof DurableDriver) {
            throw ValidationException::withMessages([
                'profile' => "Profile [{$profile->id}] does not support durable submission and retrieval.",
            ]);
        }

        return $driver;
    }

    private function response(ResearchHandle $handle, ResearchResult $result): ResearchResponse
    {
        /** @var Collection<int, ResearchError> $errors */
        $errors = collect();

        return new ResearchResponse(
            generator: self::GENERATOR,
            generatorVersion: $this->generatorVersion(),
            requestId: $handle->requestId,
            status: ResponseStatus::Succeeded,
            completedAt: $result->completedAt,
            results: collect([$result]),
            errors: $errors,
        );
    }

    private function outcome(
        ResearchHandle $handle,
        ?ResearchResponse $response,
        CaptureSession $session,
        ?RedactionPolicy $policy,
        bool $capture,
    ): DurableOutcome {
        return new DurableOutcome(
            handle: $handle,
            response: $response,
            coverage: $capture ? $session->coverage() : CaptureCoverage::Unavailable,
            evidence: $capture && $policy !== null
                ? $this->redactor->redactResponses($session->responses(), $policy)
                : collect($this->emptyEvidence()),
            diagnostics: $session->diagnostics(),
            startedAt: $session->startedAt(),
        );
    }

    /** @return array<int, RedactedProviderResponse> */
    private function emptyEvidence(): array
    {
        return [];
    }

    private function failure(
        CaptureSession $session,
        Profile $profile,
        DriverException $exception,
        Operation $operation,
    ): RecoveryException {
        $session->failure($profile, $exception, $operation);
        $diagnostic = $session->lastDiagnostic();
        if ($diagnostic === null) {
            throw new LogicException('A durable failure diagnostic could not be created.');
        }

        return new RecoveryException($exception->getMessage(), $diagnostic);
    }

    private function captureResponse(
        CaptureSession $session,
        Profile $profile,
        Response $response,
        Operation $operation,
        ?string $body,
    ): null {
        $session->capture($profile, $response, $operation, $body);

        return null;
    }

    private function captureNoResponse(CaptureSession $session, Profile $profile, Operation $operation): null
    {
        $session->noResponse($profile, $operation);

        return null;
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
