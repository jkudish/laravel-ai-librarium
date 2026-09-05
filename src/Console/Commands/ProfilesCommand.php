<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\ProfileAvailability;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;

final class ProfilesCommand extends LibrariumCommand
{
    protected $signature = 'librarium:profiles {--agent : Emit deterministic JSON for automation}';

    protected $description = 'List configured Librarium Profiles and their availability';

    public function __construct(private readonly ProfileCatalog $catalog)
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
            $profiles = $this->catalog->all();

            if ($this->agentMode()) {
                $this->writeJson([
                    'schema_version' => 1,
                    'type' => 'profiles',
                    'profiles' => $profiles->map(fn (ProfileAvailability $item): array => $this->profileEnvelope($item))->values()->all(),
                ]);

                return self::SUCCESS;
            }

            info('Librarium Profiles');
            table(
                ['Profile', 'Provider', 'Model', 'Result', 'Availability'],
                $profiles->map(fn (ProfileAvailability $item): array => [
                    $item->profile->id,
                    $item->profile->provider,
                    $item->profile->model ?? 'Provider default',
                    $item->profile->resultKind->value,
                    $item->isEligible() ? 'Eligible' : implode(' ', $item->reasons),
                ])->values()->all(),
            );

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            return $this->reportInvalid($exception);
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        }
    }

    /** @return array<string, mixed> */
    private function profileEnvelope(ProfileAvailability $item): array
    {
        $profile = $item->profile;

        return [
            'id' => $profile->id,
            'provider' => $profile->provider,
            'model' => $profile->model,
            'result_kind' => $profile->resultKind->value,
            'eligible' => $item->isEligible(),
            'reasons' => $item->reasons,
        ];
    }
}
