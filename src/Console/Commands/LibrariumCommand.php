<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

abstract class LibrariumCommand extends Command
{
    public const int EXIT_INVALID = 2;

    public const int EXIT_NOT_FOUND = 3;

    public const int EXIT_PARTIAL = 4;

    public const int EXIT_RESEARCH_FAILED = 5;

    protected function agentMode(): bool
    {
        return (bool) $this->option('agent');
    }

    /** @param array<string, mixed> $value */
    protected function writeJson(array $value): void
    {
        try {
            $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new JsonException('The command output could not be encoded.');
        }

        $this->output->getOutput()->setDecorated(false);
        $this->output->writeln($json, OutputInterface::OUTPUT_RAW);
    }

    protected function reportInvalid(ValidationException $exception): int
    {
        return $this->reportError(
            collect($exception->errors())->flatten()->filter(fn (mixed $message): bool => is_string($message))->implode(' '),
            self::EXIT_INVALID,
        );
    }

    protected function reportUnexpected(Throwable $_exception): int
    {
        return $this->reportError('The Librarium command could not be completed.', self::FAILURE);
    }

    protected function reportError(string $message, int $exitCode): int
    {
        $safeMessage = $this->safeMessage($message);

        if ($this->agentMode()) {
            $output = $this->output->getOutput();
            $output->setDecorated(false);

            if ($output instanceof ConsoleOutputInterface) {
                $output->getErrorOutput()->writeln($safeMessage, OutputInterface::OUTPUT_RAW);
            }

            return $exitCode;
        }

        $this->components->error($safeMessage);

        return $exitCode;
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        if ($message === '') {
            return 'The Librarium command could not be completed.';
        }

        return mb_strimwidth($message, 0, 500, '…');
    }
}
