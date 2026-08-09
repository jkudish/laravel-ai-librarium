<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;

final readonly class LibrariumManager implements Librarium
{
    public function __construct(private ValidationFactory $validator) {}

    public function query(string $input): PendingResearch
    {
        $this->validator->make(
            ['input' => $input],
            ['input' => ['required', 'string']],
        )->validate();

        return new PendingResearch($input);
    }
}
