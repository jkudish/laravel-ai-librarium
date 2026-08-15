<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Tests\Support;

use Jkudish\LaravelAiLibrarium\Contracts\ValidatesProfileOptions;

final class FakeProfileDriver implements ValidatesProfileOptions
{
    public static int $researchCalls = 0;

    /** @return array<string, mixed> */
    public function profileOptionRules(): array
    {
        return [
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'search_depth' => ['sometimes', 'string', 'in:basic,advanced'],
            'max_tokens' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
