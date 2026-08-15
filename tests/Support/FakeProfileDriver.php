<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Jkudish\LaravelAiLibrarium\Contracts\ValidatesProfileOptions;

final class FakeProfileDriver implements ValidatesProfileOptions
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
            'provider' => ['sometimes', 'array:mode'],
            'provider.mode' => ['sometimes', 'string'],
        ];
    }
}
