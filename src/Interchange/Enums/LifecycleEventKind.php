<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Interchange\Enums;

enum LifecycleEventKind: string
{
    case RequestStarted = 'request_started';
    case AttemptStarted = 'attempt_started';
    case DurableTaskSubmitted = 'durable_task_submitted';
    case AttemptProgress = 'attempt_progress';
    case AttemptFinished = 'attempt_finished';
    case FallbackSelected = 'fallback_selected';
    case RequestCompleted = 'request_completed';
    case RequestFailed = 'request_failed';
    case RequestCancelled = 'request_cancelled';
}
