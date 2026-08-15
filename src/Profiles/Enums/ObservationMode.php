<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Profiles\Enums;

enum ObservationMode: string
{
    case ApiOutput = 'api_output';
    case SurfaceSnapshot = 'surface_snapshot';
}
