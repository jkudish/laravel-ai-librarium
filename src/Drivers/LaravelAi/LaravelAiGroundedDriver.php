<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\LaravelAi;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;

final readonly class LaravelAiGroundedDriver implements Driver
{
    public function __construct(private ResearchResultMapper $mapper) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $model = $request->profile->model;
        if ($model === null) {
            throw new DriverException(
                errorCode: 'laravel_ai.model_required',
                message: 'The Laravel AI grounded Driver requires an exact model.',
                fallbackAllowed: false,
            );
        }

        $timeout = $this->remainingSeconds($request);
        $agent = new GroundedResearchAgent;

        try {
            $response = $agent->prompt(
                $request->prompt,
                provider: $request->profile->provider,
                model: $model,
                timeout: $timeout,
            );
        } catch (RateLimitedException) {
            throw new DriverException('laravel_ai.rate_limited', 'The AI provider rate limited the research request.');
        } catch (ProviderOverloadedException) {
            throw new DriverException('laravel_ai.provider_unavailable', 'The AI provider is temporarily unavailable.');
        } catch (InsufficientCreditsException) {
            throw new DriverException('laravel_ai.insufficient_credits', 'The AI provider could not run the request because its quota is exhausted.');
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('librarium.timeout', 'The shared research deadline was reached.', false);
            }

            throw new DriverException('laravel_ai.network', 'The AI provider could not be reached.');
        } catch (RequestException $exception) {
            throw $this->requestFailure($exception);
        } catch (ValidationException) {
            throw new DriverException('laravel_ai.validation', 'The AI provider request failed validation.', false);
        } catch (AiException) {
            throw new DriverException('laravel_ai.provider', 'The AI provider could not complete the research request.');
        }

        $result = $this->mapper->map($response, $request);

        if ($request->profile->grounding === GroundingPolicy::Required && $result->citations->isEmpty()) {
            throw new DriverException(
                'laravel_ai.ungrounded_response',
                'The AI provider returned no evidence for the grounded response.',
            );
        }

        return $result;
    }

    private function remainingSeconds(DriverRequest $request): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds <= 0) {
            throw new DriverException(
                errorCode: 'librarium.timeout',
                message: 'The shared research deadline was reached.',
                fallbackAllowed: false,
            );
        }

        return max(1, (int) ceil($seconds));
    }

    private function requestFailure(RequestException $exception): DriverException
    {
        $status = $exception->response->status();

        return match (true) {
            $status === 401 => new DriverException('laravel_ai.authentication', 'The AI provider rejected its configured credentials.'),
            $status === 403 => new DriverException('laravel_ai.authorization', 'The AI provider denied this research request.'),
            $status === 408 => new DriverException('laravel_ai.timeout', 'The AI provider timed out before completing the request.'),
            $status === 429 => new DriverException('laravel_ai.rate_limited', 'The AI provider rate limited the research request.'),
            $status >= 500 => new DriverException('laravel_ai.provider_unavailable', 'The AI provider is temporarily unavailable.'),
            default => new DriverException('laravel_ai.invalid_request', 'The AI provider rejected the research request.', false),
        };
    }
}
