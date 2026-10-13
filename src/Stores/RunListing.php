<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Stores;

use Jkudish\LaravelAiLibrarium\RunSummary;

/** @internal The same ordering and exclusive cursor for non-query stores. */
final class RunListing
{
    /**
     * @param  list<RunSummary>  $runs
     * @return list<RunSummary>
     */
    public static function page(array $runs, int $limit, ?string $before): array
    {
        usort($runs, fn (RunSummary $a, RunSummary $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);
        if ($before !== null) {
            $position = array_search($before, array_map(fn (RunSummary $run): string => $run->id, $runs), true);
            $runs = $position === false ? [] : array_slice($runs, $position + 1);
        }

        return array_slice($runs, 0, max(0, $limit));
    }
}
