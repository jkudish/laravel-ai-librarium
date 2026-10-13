<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Exceptions\SelectionException;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\LibrariumManager;
use Throwable;

final class ResearchCommand extends LibrariumCommand
{
    protected $signature = 'librarium:research {question : Research question}
        {--deep : Select deep research variants}
        {--only= : Comma-separated sources, engines or groups}
        {--skip= : Comma-separated exclusions}
        {--max-cost= : USD budget}
        {--dry-run : Plan without calls or storage}
        {--no-wait : Queue and print the Run id}
        {--json : Emit the public Run JSON}';

    protected $description = 'Research a question and store its Run';

    private bool $interrupted = false;

    public function handle(LibrariumManager $manager): int
    {
        try {
            $question = $this->argument('question');
            $request = ['question' => $question, 'depth' => $this->option('deep') ? 'deep' : 'quick', 'skip' => [], 'options' => []];
            foreach (['only', 'skip'] as $name) {
                $value = $this->option($name);
                if (is_string($value)) {
                    $request[$name] = array_map('trim', explode(',', $value));
                }
            }
            $maximum = $this->option('max-cost');
            if (is_string($maximum)) {
                $request['max_cost'] = $maximum;
            }
            if ($this->option('dry-run')) {
                return $this->writeRun($manager->plan($request)->run);
            }
            if ($this->option('no-wait')) {
                $queued = $manager->enqueue($request);
                $run = $manager->find($queued->id());
                if ($run === null) {
                    return self::FAILURE;
                }

                return $this->writeRun($run);
            }
            $id = $manager->create($request)->run->id();
            $runtime = app(RunRuntime::class);
            $this->interrupted = false;
            if (defined('SIGINT')) {
                // A signal can arrive inside a store's critical section. Never reenter it.
                $this->trap(SIGINT, function (): void {
                    $this->interrupted = true;
                });
            }
            if (! $this->option('json')) {
                $this->line('Run '.$id.' — Ctrl-C detaches; saved provider tasks keep running.');
            }
            $deadline = CarbonImmutable::now()->addSeconds($manager->timeout());
            $run = $runtime->start($id);
            $previous = null;
            while ($run->status() === 'running' && CarbonImmutable::now()->lessThan($deadline)) {
                $progress = implode(', ', array_map(fn (array $result): string => $result['source'].': '.$result['status'], $run->toArray()['results']));
                if (! $this->option('json') && $progress !== $previous) {
                    $this->line($progress);
                    $previous = $progress;
                }
                Sleep::for(1)->seconds();
                if ($this->interrupted) {
                    break;
                }
                $run = $run->refresh();
            }
            if ($this->interrupted) {
                $runtime->failInterrupted($id);
                $run = $manager->find($id);
                if ($run !== null) {
                    $this->writeRun($run);
                }
                if (! $this->option('json')) {
                    $this->line('Detached. Return with: php artisan librarium:show '.$id.' --wait');
                }

                return 130;
            }

            return $this->writeRun($run);
        } catch (ValidationException $exception) {
            return $this->reportInvalid($exception);
        } catch (SelectionException $exception) {
            return $this->reportError($exception->getMessage(), self::EXIT_INVALID);
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        } finally {
            $this->untrap();
        }
    }
}
