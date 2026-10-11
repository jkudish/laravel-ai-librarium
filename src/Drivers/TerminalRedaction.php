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
     * When $taskHandle names the private task, the Valyu PDF artifact's
     * url may contain it if ValyuSupport::isTaskAssetLink() validates that
     * whole value for the task. The handle in any other field, including the
     * same link in report text, and every other private value still reject
     * the result.
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
        if ($checked === []) {
            return $result;
        }

        $value = $result->toArray();
        if ($taskHandle !== null) {
            $value = self::withoutTaskAssetLinks($value, $taskHandle, $privateValues);
        }

        if (self::contains($value, $checked)) {
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
     * A copy of the serialized result without the validated PDF artifact
     * urls under provider_meta["valyu:research"].artifacts.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $privateValues
     * @return array<string, mixed>
     */
    private static function withoutTaskAssetLinks(array $value, string $taskHandle, array $privateValues): array
    {
        $meta = $value['provider_meta'] ?? null;
        if (! $meta instanceof stdClass) {
            return $value;
        }

        $meta = get_object_vars($meta);
        $research = $meta['valyu:research'] ?? null;
        $artifacts = is_array($research) ? ($research['artifacts'] ?? null) : null;
        if (! is_array($research) || ! is_array($artifacts)) {
            return $value;
        }

        $kept = [];
        foreach ($artifacts as $index => $artifact) {
            if (is_array($artifact)
                && ($artifact['kind'] ?? null) === 'pdf'
                && is_string($artifact['url'] ?? null)
                && ValyuSupport::isTaskAssetLink($artifact['url'], $taskHandle, $privateValues)) {
                unset($artifact['url']);
            }
            $kept[$index] = $artifact;
        }
        $research['artifacts'] = $kept;
        $meta['valyu:research'] = $research;
        $value['provider_meta'] = $meta;

        return $value;
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
