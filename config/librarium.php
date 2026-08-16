<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiGroundedDriver;

return [
    'default_group' => 'quick',
    'timeout' => 6900,
    'status_ttl' => 10800,
    'webhook_signal_ttl' => 10800,
    'profile_options_max_bytes' => 16384,

    'queue' => [
        'connection' => null,
        'name' => 'research',
        'timeout' => 7200,
    ],

    'profiles' => [
        'gemini-grounded' => [
            'driver' => LaravelAiGroundedDriver::class,
            'provider' => 'gemini',
            'model' => 'gemini-3.6-flash',
            'result_kind' => 'grounded_answer',
            'grounding' => 'required',
            'observation' => 'api_output',
            'corpora' => ['web'],
            'retrieval_methods' => ['model_search_tool'],
            'prompt' => '{{ query }}',
            'enabled' => true,
            'options' => [],
            'credential' => config('ai.providers.gemini.key'),
        ],
    ],

    'groups' => [],
];
