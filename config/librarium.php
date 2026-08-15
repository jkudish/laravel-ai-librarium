<?php

declare(strict_types=1);

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

    'profiles' => [],

    'groups' => [],
];
