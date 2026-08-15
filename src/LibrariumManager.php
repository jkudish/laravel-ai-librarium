<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;

final readonly class LibrariumManager implements Librarium
{
    public function __construct(
        private ValidationFactory $validator,
        private ResearchCompiler $compiler,
        private ExecutesResearch $executor,
        private ResearchStatusStore $statusStore,
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
            executor: $this->executor,
        );
    }

    public function status(string $requestId): ?ResearchStatus
    {
        $this->validator->make(['request_id' => $requestId], ['request_id' => ['required', 'string', 'min:1']])->validate();

        return $this->statusStore->get($requestId);
    }
}
