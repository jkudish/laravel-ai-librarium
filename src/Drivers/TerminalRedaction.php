<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers;

use Jkudish\LaravelAiLibrarium\Drivers\Valyu\ValyuSupport;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use stdClass;

/** Rejects terminal values that reflect credentials or private provider handles. */
final class TerminalRedaction
{
    /**
     * When $taskHandle names the private task, a whole string value that is
     * that task's validated Valyu asset link may contain it (see
     * ValyuSupport::isTaskAssetLink()). The handle anywhere else, including
     * a link embedded in other text or another URL, and every other private
     * value still reject the result.
     *
     * @param  list<string>  $privateValues
     */
    public static function guard(
        ResearchResult $result,
        array $privateValues,
        ?DriverException $failure = null,
        ?string $taskHandle = null,
    ): ResearchResult {
        $checked = array_values(array_filter(
            $privateValues,
            static fn (string $value): bool => $value !== '',
        ));

        if ($checked !== [] && self::contains($result->toArray(), $checked, $privateValues, $taskHandle)) {
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

    /**
     * @param  list<string>  $checked
     * @param  list<string>  $privateValues
     */
    private static function contains(mixed $value, array $checked, array $privateValues, ?string $taskHandle): bool
    {
        if (is_string($value)) {
            if ($taskHandle !== null && ValyuSupport::isTaskAssetLink($value, $taskHandle, $privateValues)) {
                return false;
            }

            return array_any(
                $checked,
                static fn (string $privateValue): bool => str_contains($value, $privateValue),
            );
        }

        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value)
            && array_any($value, static fn (mixed $item): bool => self::contains($item, $checked, $privateValues, $taskHandle));
    }
}
