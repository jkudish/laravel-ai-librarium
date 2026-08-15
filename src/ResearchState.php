<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

enum ResearchState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
