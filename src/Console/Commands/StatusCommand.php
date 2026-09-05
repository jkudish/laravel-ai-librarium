<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

final class StatusCommand extends LibrariumCommand
{
    protected $signature = 'librarium:status
        {requestId? : The queued research request ID}
        {--agent : Emit deterministic JSON for automation}';

    protected $description = 'Show the current status of a Librarium request';

    public function __construct(private readonly Librarium $librarium)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->agentMode()) {
            $this->input->setInteractive(false);
            $this->output->getOutput()->setDecorated(false);
        }

        try {
            $requestId = $this->requestId();
            $status = $this->librarium->status($requestId);

            if ($status === null) {
                return $this->reportError(
                    $this->agentMode()
                        ? 'No status was found for that request.'
                        : "No status was found for request [{$requestId}].",
                    self::EXIT_NOT_FOUND,
                );
            }

            if ($this->agentMode()) {
                $this->writeJson([
                    'schema_version' => 1,
                    'type' => 'status',
                    'request_id' => $requestId,
                    'status' => $status->toArray(),
                ]);

                return self::SUCCESS;
            }

            info("Librarium request {$requestId}");
            table([], [
                ['State', $status->state->value],
                ['Progress', $status->progress ?? '—'],
                ['Updated', $status->updatedAt->toIso8601String()],
            ]);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            return $this->reportInvalid($exception);
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        }
    }

    private function requestId(): string
    {
        $requestId = $this->argument('requestId');
        if (is_string($requestId) && $requestId !== '') {
            return $requestId;
        }

        if (! $this->input->isInteractive() || $this->agentMode()) {
            throw ValidationException::withMessages(['request_id' => 'The requestId argument is required in non-interactive mode.']);
        }

        return text('Request ID', required: true);
    }
}
