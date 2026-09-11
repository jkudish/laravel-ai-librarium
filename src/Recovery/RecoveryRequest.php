<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Recovery;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Response;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Responses\Usage;

/** @internal */
final readonly class RecoveryRequest
{
    /**
     * @param  Closure(Response, Operation, ?string): void  $responseCallback
     * @param  Closure(Operation): void  $noResponseCallback
     */
    public function __construct(
        public string $requestId,
        public Profile $requestedProfile,
        public Profile $profile,
        public CarbonImmutable $deadline,
        private Closure $responseCallback,
        private Closure $noResponseCallback,
        public ?Usage $submittedUsage = null,
    ) {}

    public function captureResponse(
        Response $response,
        Operation $operation = Operation::Retrieve,
        ?string $body = null,
    ): void {
        ($this->responseCallback)($response, $operation, $body);
    }

    public function captureNoResponse(Operation $operation = Operation::Retrieve): void
    {
        ($this->noResponseCallback)($operation);
    }
}
