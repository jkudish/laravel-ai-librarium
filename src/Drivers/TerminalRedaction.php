<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers;

use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use stdClass;

/** Rejects terminal values that reflect credentials or private provider handles. */
final class TerminalRedaction
{
    /** @param list<string> $privateValues */
    public static function guard(
        ResearchResult $result,
        array $privateValues,
        ?DriverException $failure = null,
    ): ResearchResult {
        $privateValues = array_values(array_filter(
            $privateValues,
            static fn (string $value): bool => $value !== '',
        ));

        if ($privateValues !== [] && self::contains($result->toArray(), $privateValues)) {
            if ($failure === null) {
                throw new DriverException(
                    'librarium.unsafe_result',
                    'The provider result contained private credential or task material.',
                    false,
                );
            }

            throw new DriverException(
                $failure->errorCode,
                $failure->getMessage(),
                false,
            );
        }

        return $result;
    }

    /** @param list<string> $privateValues */
    private static function contains(mixed $value, array $privateValues): bool
    {
        if (is_string($value)) {
            return array_any(
                $privateValues,
                static fn (string $privateValue): bool => str_contains($value, $privateValue),
            );
        }

        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value)
            && array_any($value, static fn (mixed $item): bool => self::contains($item, $privateValues));
    }
}
