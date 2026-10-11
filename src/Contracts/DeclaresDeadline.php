<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Contracts;

use Jkudish\LaravelAiLibrarium\Profile;

/** A Driver that polls a provider task until a Profile-specific deadline, capped by the shared research timeout. */
interface DeclaresDeadline
{
    /** The seconds the Driver waits for this Profile, or null when the Profile names no supported target. */
    public static function deadlineSeconds(Profile $profile): ?int;
}
