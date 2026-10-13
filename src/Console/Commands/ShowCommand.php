<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Throwable;

final class ShowCommand extends LibrariumCommand
{
    protected $signature = 'librarium:show {id? : Run id (latest by default)} {--wait : Wait for pending sources} {--source= : Show one source} {--json : Emit Run JSON}';

    protected $description = 'Find a Run and observe pending deep sources';

    public function handle(LibrariumManager $manager): int
    {
        try {
            $id = $this->argument('id');
            $id = is_string($id) ? $id : ($manager->store()->list(1)[0]->id ?? null);
            $run = $id === null ? null : $manager->find($id);
            if ($run === null) {
                return $this->reportError('Run not found.', self::EXIT_NOT_FOUND);
            }
            $deadline = CarbonImmutable::now()->addSeconds($manager->timeout());
            do {
                $run = $run->refresh();
                if (! $this->option('wait') || $run->status() !== 'running' || CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                    break;
                }
                Sleep::for(1)->seconds();
            } while (true);
            $source = $this->option('source');
            if (is_string($source)) {
                $result = $run->result($source);
                if ($result === null) {
                    return $this->reportError('Source not found in this Run.', self::EXIT_NOT_FOUND);
                }
                if ($this->option('json')) {
                    $this->writeJson($result);
                } else {
                    $this->line($result['answer'] ?? json_encode($result['citations'], JSON_THROW_ON_ERROR), verbosity: null);
                }

                return self::SUCCESS;
            }

            return $this->writeRun($run);
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        }
    }
}
