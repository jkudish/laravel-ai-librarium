<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use LogicException;

final class FakeProfileDriver implements Driver
{
    public static int $constructorCalls = 0;

    public static int $validationCalls = 0;

    public function __construct()
    {
        self::$constructorCalls++;
    }

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        self::$validationCalls++;

        return [
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'search_depth' => ['sometimes', 'string', 'in:basic,advanced'],
            'max_tokens' => ['sometimes', 'integer', 'min:1'],
            'prompt_tokens' => ['sometimes', 'integer', 'min:1'],
            'completion_tokens' => ['sometimes', 'integer', 'min:1'],
            'reasoning_tokens' => ['sometimes', 'integer', 'min:1'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1'],
            'max_completion_tokens' => ['sometimes', 'integer', 'min:1'],
            'provider' => ['sometimes', 'array:mode'],
            'provider.mode' => ['sometimes', 'string'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        throw new LogicException('FakeProfileDriver does not execute research.');
    }
}
