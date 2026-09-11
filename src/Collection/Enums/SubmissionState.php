<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Collection\Enums;

enum SubmissionState: string
{
    case NotSent = 'not_sent';
    case Rejected = 'rejected';
    case PossiblyAccepted = 'possibly_accepted';
    case Accepted = 'accepted';
}
