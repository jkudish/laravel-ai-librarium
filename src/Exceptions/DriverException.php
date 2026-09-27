<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Exceptions;

use Illuminate\Support\Facades\Validator;
use RuntimeException;

final class DriverException extends RuntimeException
{
    public readonly ?string $observedModel;

    /**
     * The message is terminal interchange text. Drivers must provide a safe summary,
     * never credentials, request headers, or an unrestricted provider response.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $fallbackAllowed = true,
        ?string $observedModel = null,
    ) {
        // Diagnostic metadata must never replace the original failure with a
        // validation error. Keep only bounded model identifiers, not URLs/text.
        $this->observedModel = $observedModel !== null && strlen($observedModel) <= 255
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*(?:[\/:][A-Za-z0-9][A-Za-z0-9._-]*)*\z/', $observedModel) === 1
                ? $observedModel : null;

        Validator::make(
            ['code' => $errorCode, 'message' => $message],
            [
                'code' => ['required', 'string', 'min:3', 'max:255', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*(?:[.:\/][A-Za-z0-9_.-]+)+$/'],
                'message' => ['required', 'string', 'min:1', 'max:2048', 'regex:/^(?!\s)(?!.*\s$)[^\x00-\x1f\x7f]+$/u'],
            ],
        )->validate();

        parent::__construct($message);
    }
}
