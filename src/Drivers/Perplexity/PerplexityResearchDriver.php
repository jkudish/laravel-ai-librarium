<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Perplexity;

use Jkudish\LaravelAiLibrarium\Contracts\DurableDriver;

/** Medium/high-effort variants submit a recoverable provider response. */
final readonly class PerplexityResearchDriver extends PerplexityAgentDriver implements DurableDriver {}
