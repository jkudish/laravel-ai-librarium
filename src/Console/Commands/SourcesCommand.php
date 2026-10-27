<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Throwable;

final class SourcesCommand extends LibrariumCommand
{
    protected $signature = 'librarium:sources {--json : Emit sources, engines and groups as JSON}';

    protected $description = 'List sources, variants and offline price hints';

    public function handle(LibrariumManager $manager): int
    {
        try {
            $catalog = $manager->sources();
            if ($this->option('json')) {
                $this->writeJson($catalog);
            } else {
                foreach (['sources' => 'Sources', 'engines' => 'Engines (named only)'] as $key => $title) {
                    $this->line($title);
                    /** @var list<array{id: string, configured: bool, variants: array<string, mixed>, prices: array<string, ?string>}> $entries */
                    $entries = $catalog[$key];
                    $this->table(['Name', 'Set up', 'Depths', 'Bounded estimates (USD)'], array_map(fn (array $entry): array => [
                        $entry['id'], $entry['configured'] ? 'yes' : 'no', $key === 'engines' ? 'quick' : implode(', ', array_keys($entry['variants'])),
                        implode(', ', array_map(fn (string $depth, ?string $price): string => $depth.': '.($price ?? 'unknown'), array_keys($entry['prices']), array_values($entry['prices']))),
                    ], $entries));
                }
                /** @var array<string, list<string>> $groups */
                $groups = $catalog['groups'];
                foreach ($groups as $name => $members) {
                    $this->line('Group '.$name.': '.implode(', ', $members));
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        }
    }
}
