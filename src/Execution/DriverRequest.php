<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Profile;

final readonly class DriverRequest
{
    /**
     * @param  Closure(string, string): void  $progressCallback
     * @param  (Closure(Response, Operation, ?string): void)|null  $responseCallback
     * @param  (Closure(Operation): void)|null  $noResponseCallback
     * @param  (Closure(): void)|null  $partialCaptureCallback
     */
    public function __construct(
        public string $requestId,
        public Profile $requestedProfile,
        public Profile $profile,
        public string $prompt,
        public CarbonImmutable $deadline,
        private Closure $progressCallback,
        private ?Closure $responseCallback = null,
        private ?Closure $noResponseCallback = null,
        private ?Closure $partialCaptureCallback = null,
    ) {}

    public function progress(string $message): void
    {
        $progress = Str::of($message)
            ->replaceMatches('/[\x00-\x1f\x7f]+/u', ' ')
            ->squish()
            ->limit(255, '')
            ->toString();

        if ($progress === '') {
            throw new InvalidArgumentException('Research progress must contain a human-readable message.');
        }

        ($this->progressCallback)($progress, $this->profile->id);
    }

    public function captureResponse(
        Response $response,
        Operation $operation = Operation::Inline,
        ?string $body = null,
    ): void {
        if ($this->responseCallback !== null) {
            ($this->responseCallback)($response, $operation, $body);
        }
    }

    public function captureNoResponse(Operation $operation = Operation::Inline): void
    {
        if ($this->noResponseCallback !== null) {
            ($this->noResponseCallback)($operation);
        }
    }

    public function markCapturePartial(): void
    {
        if ($this->partialCaptureCallback !== null) {
            ($this->partialCaptureCallback)();
        }
    }
}
