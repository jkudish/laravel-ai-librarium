<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\DataForSeo;

use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;

/** Standard-priority variants submit a recoverable provider task. */
final readonly class DataForSeoTaskDriver extends DataForSeoDriver implements DurableDriver {}
