<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Throwable;

final class PruneCommand extends LibrariumCommand
{
    protected $signature = 'librarium:prune {--json : Emit deleted count as JSON}';

    protected $description = 'Delete Runs older than runs.keep_days (disabled when unset)';

    public function handle(LibrariumManager $manager): int
    {
        try {
            $days = config('librarium.runs.keep_days');
            if ($days === null) {
                $this->writeJson(['deleted' => 0]);

                return self::SUCCESS;
            }
            if (! is_int($days) || $days < 1) {
                return $this->reportError('runs.keep_days must be a positive integer or null.', self::EXIT_INVALID);
            }
            $store = $manager->store();
            $cutoff = CarbonImmutable::now()->subDays($days);
            $ids = [];
            $before = null;
            do {
                $page = $store->list(50, $before);
                foreach ($page as $summary) {
                    if (CarbonImmutable::parse($summary->createdAt)->lessThan($cutoff)) {
                        $ids[] = $summary->id;
                    }
                    $before = $summary->id;
                }
            } while (count($page) === 50);
            // Page before deleting: exclusive cursors must still exist.
            foreach ($ids as $id) {
                $store->delete($id);
            }
            $this->writeJson(['deleted' => count($ids)]);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        }
    }
}
