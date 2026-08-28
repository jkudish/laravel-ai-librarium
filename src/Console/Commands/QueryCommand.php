<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Console\Commands;

use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\PendingResearch;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\ProfileAvailability;
use Jkudish\LaravelAiLibrarium\ProfileCatalog;
use Jkudish\LaravelAiLibrarium\ResearchPreview;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

final class QueryCommand extends LibrariumCommand
{
    protected $signature = 'librarium:query
        {query? : The research query}
        {--profile=* : Profile to use; repeat to fan out}
        {--group= : Profile group to use}
        {--fallback=* : Fallback Profile; repeat in priority order}
        {--timeout= : Timeout in seconds}
        {--max-cost= : Hard maximum estimated cost}
        {--currency= : ISO 4217 currency for --max-cost (default: USD)}
        {--run : Run synchronously after preview}
        {--queue : Queue after preview}
        {--agent : Strict non-interactive JSON automation mode}';

    protected $description = 'Preview, run, or queue a Librarium research query';

    public function __construct(
        private readonly Librarium $librarium,
        private readonly ProfileCatalog $catalog,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->agentMode()) {
            $this->input->setInteractive(false);
            $this->output->getOutput()->setDecorated(false);
        }

        if ($this->shouldRejectNonInteractiveInvocation()) {
            return $this->reportError(
                'A non-interactive query requires --agent or --no-interaction and a query argument.',
                self::EXIT_INVALID,
            );
        }

        try {
            $this->validateOptionConflicts();

            $interactive = $this->input->isInteractive() && ! $this->agentMode();
            $query = $this->queryInput($interactive);
            $pending = $this->librarium->query($query);

            $this->applySelection($pending, $interactive);
            $this->applyControls($pending, $interactive);

            $preview = $pending->preview();

            if ($this->agentMode() && ! $this->option('run') && ! $this->option('queue')) {
                $this->writeJson($this->previewEnvelope($pending, $preview));

                return self::SUCCESS;
            }

            if ($interactive) {
                $this->showPreview($pending, $preview);
            } elseif (! $this->agentMode()) {
                $this->showPlainPreview($pending, $preview);
            }

            if (! $interactive && ! $this->option('run') && ! $this->option('queue')) {
                $this->line('No research was dispatched.');

                return self::SUCCESS;
            }

            $action = $this->action($interactive);
            if ($action === 'exit') {
                if ($interactive) {
                    outro('No research was dispatched.');
                }

                return self::SUCCESS;
            }

            if ($interactive && ! confirm(
                $action === 'run' ? 'Dispatch this research now?' : 'Queue this research?',
                default: false,
            )) {
                outro('No research was dispatched.');

                return self::SUCCESS;
            }

            if ($action === 'queue') {
                $queued = $pending->queue();
                $envelope = [
                    'schema_version' => 1,
                    'type' => 'queued',
                    'request_id' => $queued->requestId,
                ];

                if ($this->agentMode()) {
                    $this->writeJson($envelope);
                } else {
                    outro("Research queued. Request ID: {$queued->requestId}");
                }

                return self::SUCCESS;
            }

            return $this->writeResponse($pending->run());
        } catch (ValidationException $exception) {
            return $this->reportInvalid($exception);
        } catch (Throwable $exception) {
            return $this->reportUnexpected($exception);
        }
    }

    private function shouldRejectNonInteractiveInvocation(): bool
    {
        if ($this->agentMode() || ! $this->input->isInteractive()) {
            return false;
        }

        if ($this->laravel->runningUnitTests()) {
            return false;
        }

        return ! defined('STDIN') || ! stream_isatty(STDIN);
    }

    private function validateOptionConflicts(): void
    {
        $profiles = $this->stringListOption('profile');
        $group = $this->stringOption('group');

        if ($profiles !== [] && $group !== null) {
            throw ValidationException::withMessages(['selector' => '--profile and --group cannot be combined.']);
        }

        if ($this->option('run') && $this->option('queue')) {
            throw ValidationException::withMessages(['action' => '--run and --queue cannot be combined.']);
        }

        if ($this->stringOption('currency') !== null && $this->stringOption('max-cost') === null) {
            throw ValidationException::withMessages(['currency' => '--currency requires --max-cost.']);
        }
    }

    private function queryInput(bool $interactive): string
    {
        $query = $this->argument('query');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        if (! $interactive) {
            throw ValidationException::withMessages(['query' => 'The query argument is required in non-interactive mode.']);
        }

        return text('What would you like to research?', required: true);
    }

    private function applySelection(PendingResearch $pending, bool $interactive): void
    {
        $profiles = $this->stringListOption('profile');
        $group = $this->stringOption('group');

        if ($profiles !== []) {
            count($profiles) === 1 ? $pending->using($profiles[0]) : $pending->fanOut($profiles);

            return;
        }

        if ($group !== null) {
            $pending->group($group);

            return;
        }

        if (! $interactive) {
            return;
        }

        $eligible = $this->eligibleProfileOptions();
        if ($eligible === []) {
            throw ValidationException::withMessages(['profiles' => 'No eligible Profiles are configured.']);
        }

        $choice = $this->promptString(select(
            'How should Librarium select Profiles?',
            [
                'default' => 'Default group',
                ...collect($this->groupNames())->mapWithKeys(fn (string $name): array => ["group:{$name}" => "Group: {$name}"])->all(),
                ...collect($eligible)->mapWithKeys(fn (string $label, string $id): array => ["profile:{$id}" => "Profile: {$label}"])->all(),
                'multiple' => 'Multiple Profiles (fan out)',
            ],
            default: 'default',
        ));

        if ($choice === 'default') {
            return;
        }

        if ($choice === 'multiple') {
            $selected = $this->promptStringList(multiselect('Choose Profiles', $eligible, required: true));
            $pending->fanOut($selected);

            return;
        }

        if (str_starts_with($choice, 'group:')) {
            $pending->group(substr($choice, 6));

            return;
        }

        $pending->using(substr($choice, 8));
    }

    private function applyControls(PendingResearch $pending, bool $interactive): void
    {
        $fallbacks = $this->stringListOption('fallback');
        $timeout = $this->stringOption('timeout');
        $maximumCost = $this->stringOption('max-cost');
        $currency = $this->stringOption('currency');

        if ($interactive && $fallbacks === [] && $timeout === null && $maximumCost === null && $currency === null) {
            $controls = multiselect('Optional controls', [
                'fallbacks' => 'Fallback Profiles',
                'timeout' => 'Timeout',
                'max_cost' => 'Maximum estimated cost',
            ]);

            if (in_array('fallbacks', $controls, true)) {
                $fallbacks = $this->promptStringList(multiselect('Choose fallback Profiles', $this->eligibleProfileOptions(), required: true));
            }
            if (in_array('timeout', $controls, true)) {
                $timeout = text('Timeout in seconds', required: true, validate: fn (string $value): ?string => ctype_digit($value) ? null : 'Enter a whole number of seconds.');
            }
            if (in_array('max_cost', $controls, true)) {
                $maximumCost = text('Maximum estimated cost', required: true);
                $currency = text('Currency', default: 'USD', required: true);
            }
        }

        if ($fallbacks !== []) {
            $pending->fallbacks($fallbacks);
        }
        if ($timeout !== null) {
            if (! ctype_digit($timeout)) {
                throw ValidationException::withMessages(['timeout' => 'The timeout must be a whole number of seconds.']);
            }
            $pending->timeout((int) $timeout);
        }
        if ($maximumCost !== null) {
            $pending->maxCost($maximumCost, $currency ?? 'USD');
        }
    }

    private function action(bool $interactive): string
    {
        if ($this->option('run')) {
            return 'run';
        }
        if ($this->option('queue')) {
            return 'queue';
        }

        return $interactive
            ? $this->promptString(select('What would you like to do?', [
                'exit' => 'Exit without dispatching',
                'run' => 'Run now',
                'queue' => 'Queue',
            ], default: 'exit'))
            : 'exit';
    }

    private function showPreview(PendingResearch $pending, ResearchPreview $preview): void
    {
        info('Compiled research preview');
        note($pending->input);
        table(
            ['Role', 'Profile', 'Provider', 'Model'],
            [
                ...$preview->profiles->map(fn (Profile $profile): array => ['Primary', $profile->id, $profile->provider, $profile->model ?? 'Provider default'])->all(),
                ...$preview->fallbacks->map(fn (Profile $profile): array => ['Fallback', $profile->id, $profile->provider, $profile->model ?? 'Provider default'])->all(),
            ],
        );

        foreach ($preview->unavailable as $profile => $reasons) {
            warning("Unavailable {$profile}: ".implode(' ', $reasons));
        }
        foreach ($preview->warnings as $message) {
            warning($message);
        }

        $cost = $preview->maximumEstimatedCost === null
            ? 'Unavailable'
            : "{$preview->maximumEstimatedCost} {$preview->currency}";
        note('Estimated maximum cost: '.$cost.'; completeness: '.($preview->costEstimateComplete ? 'complete' : 'incomplete').'.');
    }

    private function showPlainPreview(PendingResearch $pending, ResearchPreview $preview): void
    {
        $this->line('Research preview');
        $this->line('Request ID: '.$pending->requestId);
        $this->line('Profiles: '.$preview->profiles->pluck('id')->implode(', '));
        $this->line('Fallbacks: '.($preview->fallbacks->isEmpty() ? 'none' : $preview->fallbacks->pluck('id')->implode(', ')));
        $this->line('Cost estimate: '.($preview->maximumEstimatedCost === null ? 'unavailable' : $preview->maximumEstimatedCost.' '.$preview->currency));
        $this->line('Cost completeness: '.($preview->costEstimateComplete ? 'complete' : 'incomplete'));
    }

    private function writeResponse(ResearchResponse $response): int
    {
        if ($this->agentMode()) {
            $this->writeJson($response->toArray());
        } else {
            $this->components->info("Research {$response->status->value}. Request ID: {$response->requestId}");
            foreach ($response->results as $result) {
                $this->line(is_string($result->content)
                    ? $result->content
                    : json_encode($result->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            foreach ($response->errors as $error) {
                $this->components->error("{$error->code}: {$error->message}");
            }
        }

        return match ($response->status) {
            ResponseStatus::Succeeded => self::SUCCESS,
            ResponseStatus::Partial => self::EXIT_PARTIAL,
            ResponseStatus::Failed => self::EXIT_RESEARCH_FAILED,
        };
    }

    /** @return array<string, mixed> */
    private function previewEnvelope(PendingResearch $pending, ResearchPreview $preview): array
    {
        return [
            'schema_version' => 1,
            'type' => 'preview',
            'request_id' => $pending->requestId,
            'query' => $preview->input,
            'profiles' => $preview->profiles->pluck('id')->values()->all(),
            'fallbacks' => $preview->fallbacks->pluck('id')->values()->all(),
            'unavailable' => $preview->unavailable->all(),
            'warnings' => $preview->warnings->values()->all(),
            'cost' => [
                'amount' => $preview->maximumEstimatedCost,
                'currency' => $preview->currency,
                'complete' => $preview->costEstimateComplete,
            ],
        ];
    }

    /** @return array<string, string> */
    private function eligibleProfileOptions(): array
    {
        return $this->catalog->all()
            ->filter(fn (ProfileAvailability $item): bool => $item->isEligible())
            ->mapWithKeys(fn (ProfileAvailability $item): array => [
                $item->profile->id => $item->profile->id.' ('.$item->profile->provider.')',
            ])
            ->all();
    }

    /** @return list<string> */
    private function groupNames(): array
    {
        $custom = config('librarium.groups', []);
        $customNames = is_array($custom)
            ? array_values(array_filter(array_keys($custom), 'is_string'))
            : [];

        return array_values(array_unique(['quick', 'deep', 'visibility', 'all', ...$customNames]));
    }

    /** @return list<string> */
    private function stringListOption(string $name): array
    {
        $value = $this->option($name);

        if (! is_array($value) || ! array_is_list($value) || collect($value)->contains(fn (mixed $item): bool => ! is_string($item))) {
            throw ValidationException::withMessages([$name => "The --{$name} option is malformed."]);
        }

        /** @var list<string> $value */
        return $value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$name => "The --{$name} option is malformed."]);
        }

        return $value;
    }

    private function promptString(int|string $value): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['prompt' => 'The selected prompt value is invalid.']);
        }

        return $value;
    }

    /**
     * @param  array<int|string, int|string>  $values
     * @return list<string>
     */
    private function promptStringList(array $values): array
    {
        if (collect($values)->contains(fn (mixed $value): bool => ! is_string($value))) {
            throw ValidationException::withMessages(['prompt' => 'The selected prompt values are invalid.']);
        }

        /** @var array<int|string, string> $values */
        return array_values($values);
    }
}
