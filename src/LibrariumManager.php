<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Collection\ResponseRedactor;
use Jkudish\LaravelAiLibrarium\Contracts\CollectsResearch;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;

final readonly class LibrariumManager implements Librarium
{
    public function __construct(
        private ValidationFactory $validator,
        private ResearchCompiler $compiler,
        private Container $container,
        private ResearchStatusStore $statusStore,
        private ResponseRedactor $redactor,
    ) {}

    public function query(string $input): PendingResearch
    {
        $this->validator->make(
            ['input' => $input],
            ['input' => ['required', 'string']],
        )->validate();

        return new PendingResearch(
            input: $input,
            requestId: Str::ulid()->toString(),
            validator: $this->validator,
            compiler: $this->compiler,
            resolveExecutor: fn (): ExecutesResearch => $this->container->make(ExecutesResearch::class),
            resolveCollector: fn (): CollectsResearch => $this->container->make(CollectsResearch::class),
            resolveRecovery: fn (): RecoversResearch => $this->container->make(RecoversResearch::class),
            redactor: $this->redactor,
        );
    }

    public function retrieve(ResearchHandle $handle, bool $capture = true): DurableOutcome
    {
        return $this->container->make(RecoversResearch::class)->retrieve($handle, $capture);
    }

    public function status(string $requestId): ?ResearchStatus
    {
        $this->validator->make(['request_id' => $requestId], ['request_id' => ['required', 'string', 'min:1']])->validate();

        return $this->statusStore->get($requestId);
    }
}
