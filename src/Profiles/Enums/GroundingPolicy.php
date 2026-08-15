<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Profiles\Enums;

enum GroundingPolicy: string
{
    case Required = 'required';
    case Optional = 'optional';
    case None = 'none';
}
