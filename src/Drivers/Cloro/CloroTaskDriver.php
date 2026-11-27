<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Cloro;

use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;

/** Standard-priority surfaces submit a recoverable Cloro async task. */
final readonly class CloroTaskDriver extends CloroDriver implements DurableDriver {}
