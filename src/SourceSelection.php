<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Brick\Math\BigDecimal;
use Jkudish\LaravelAiLibrarium\Exceptions\SelectionException;

/** Deterministic selection shared by planning and execution. */
final class SourceSelection
{
    /**
     * @param  array<string, array{configured: bool, deep: bool, collector: bool, engine: bool}>  $catalog  Catalog order.
     * @param  array<string, list<string>>  $groups
     * @param  list<string>|null  $only
     * @param  list<string>  $skip
     * @param  array<string, ?string>  $estimates  Bounded USD estimates only.
     * @return array{selected: list<string>, skipped: list<array{source: string, reason: string}>, estimated: ?string}
     */
    public static function select(array $catalog, array $groups, string $depth = 'quick', ?array $only = null, array $skip = [], ?string $maxCost = null, array $estimates = []): array
    {
        foreach ($groups as $name => $members) {
            if (isset($catalog[$name])) {
                throw new SelectionException('group_name_conflict', $name);
            }
        }
        $requested = $only === null
            ? array_keys(array_filter($catalog, fn (array $item): bool => $item['configured'] && ! $item['engine']))
            : self::expand($only, $groups, array_keys($catalog));
        if ($only === null && $requested === []) {
            throw new SelectionException('no_sources');
        }
        if ($only !== null) {
            foreach ($requested as $name) {
                if (! $catalog[$name]['configured']) {
                    throw new SelectionException('not_configured', $name);
                }
            }
        }
        $excluded = self::expand($skip, $groups, array_keys($catalog));
        $selected = [];
        $skipped = [];
        $sum = BigDecimal::zero();
        $complete = true;
        foreach ($catalog as $name => $item) {
            if (! in_array($name, $requested, true)) {
                continue;
            }
            $estimate = $estimates[$name] ?? null;
            $reason = match (true) {
                in_array($name, $excluded, true) => 'excluded',
                $depth === 'deep' && ! $item['deep'] => 'no_deep_variant',
                $item['engine'] && ! $item['collector'] => 'no_collector',
                $maxCost !== null && $estimate === null => 'unpriced',
                $maxCost !== null && $sum->plus($estimate)->isGreaterThan($maxCost) => 'over_budget',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['source' => $name, 'reason' => $reason];

                continue;
            }
            $selected[] = $name;
            $complete = $complete && $estimate !== null;
            $sum = $sum->plus($estimate ?? '0');
        }

        return ['selected' => $selected, 'skipped' => $skipped, 'estimated' => $complete ? (string) $sum : null];
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, list<string>>  $groups
     * @param  list<string>  $known
     * @return list<string>
     */
    private static function expand(array $names, array $groups, array $known): array
    {
        $expanded = [];
        foreach ($names as $name) {
            foreach ($groups[$name] ?? [$name] as $member) {
                if (! in_array($member, $known, true)) {
                    throw new SelectionException('unknown_name', $member);
                }
                $expanded[$member] = true;
            }
        }

        return array_keys($expanded);
    }
}
