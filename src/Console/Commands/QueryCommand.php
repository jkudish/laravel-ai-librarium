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
use League\CommonMark\CommonMarkConverter;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;
use function Termwind\parse;

final class QueryCommand extends LibrariumCommand
{
    protected $signature = 'librarium:query
        {query? : The research query}
        {--profile=* : Profile to use; repeat to fan out}
        {--group= : Profile group to use}
        {--fallback=* : Fallback Profile; repeat in priority order}
        {--timeout= : Timeout in seconds}
        {--max-cost= : Hard maximum cost}
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
            $promptForCostCeiling = $this->applyControls($pending, $interactive);

            $preview = $pending->preview();

            if ($this->agentMode() && ! $this->option('run') && ! $this->option('queue')) {
                $this->writeJson($this->previewEnvelope($pending, $preview));

                return self::SUCCESS;
            }

            if ($interactive) {
                $this->showPreview($pending, $preview);

                if ($promptForCostCeiling && ! $this->applyInteractiveCostCeiling($pending, $preview)) {
                    outro('No research was dispatched.');

                    return self::SUCCESS;
                }
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

        $selectionOptions = [
            'default' => 'Default: '.$this->groupLabel($this->defaultGroupName()),
            'multiple' => 'Choose Profiles (fan out)',
            ...collect($this->groupNames())->mapWithKeys(fn (string $name): array => ["group:{$name}" => $this->groupLabel($name)])->all(),
            ...collect($eligible)->mapWithKeys(fn (string $label, string $id): array => ["profile:{$id}" => "Profile: {$label}"])->all(),
        ];

        $choice = $this->promptString(select(
            'How should Librarium select Profiles?',
            $selectionOptions,
            default: 'default',
            scroll: min(10, count($selectionOptions)),
            hint: 'Groups and fan-out run eligible Profiles sequentially.',
            info: fn (int|string $value): string => $this->selectionInfo((string) $value),
        ));

        if ($choice === 'default') {
            return;
        }

        if ($choice === 'multiple') {
            $selected = $this->promptStringList(multiselect(
                'Choose Profiles to fan out',
                $eligible,
                scroll: min(10, count($eligible)),
                required: true,
                hint: 'Space selects; Ctrl+A toggles all; Enter continues.',
            ));
            $pending->fanOut($selected);

            return;
        }

        if (str_starts_with($choice, 'group:')) {
            $pending->group(substr($choice, 6));

            return;
        }

        $pending->using(substr($choice, 8));
    }

    private function applyControls(PendingResearch $pending, bool $interactive): bool
    {
        $fallbacks = $this->stringListOption('fallback');
        $timeout = $this->stringOption('timeout');
        $maximumCost = $this->stringOption('max-cost');
        $currency = $this->stringOption('currency');
        $promptForCostCeiling = false;

        if ($interactive && $fallbacks === [] && $timeout === null && $maximumCost === null && $currency === null) {
            $controls = multiselect('Add safeguards (optional)', [
                'fallbacks' => 'Fallback Profiles — try alternatives after a failure',
                'timeout' => 'Timeout — override the configured deadline',
                'max_cost' => 'Cost ceiling — require a conservative hard bound',
            ], hint: 'Space selects; Enter continues with the configured defaults.');

            if (in_array('fallbacks', $controls, true)) {
                $fallbacks = $this->promptStringList(multiselect('Choose fallback Profiles', $this->eligibleProfileOptions(), required: true));
            }
            if (in_array('timeout', $controls, true)) {
                $timeout = text('Timeout in seconds', required: true, validate: fn (string $value): ?string => ctype_digit($value) ? null : 'Enter a whole number of seconds.');
            }
            if (in_array('max_cost', $controls, true)) {
                $promptForCostCeiling = true;
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

        return $promptForCostCeiling;
    }

    private function applyInteractiveCostCeiling(PendingResearch $pending, ResearchPreview $preview): bool
    {
        if (! $preview->hardCostCeilingAvailable
            || $preview->maximumEstimatedCost === null
            || $preview->currency === null) {
            warning("A cost ceiling isn't available for this selection.");

            return false;
        }

        $maximumCost = text(
            "Maximum cost in {$preview->currency}",
            default: $preview->maximumEstimatedCost,
            required: true,
            hint: "Suggested from the conservative bound: {$preview->maximumEstimatedCost} {$preview->currency}.",
        );
        $pending->maxCost($maximumCost, $preview->currency);

        note("Cost ceiling set to {$maximumCost} {$preview->currency}.");

        return true;
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
                'run' => 'Run now',
                'queue' => 'Queue',
                'exit' => 'Exit without dispatching',
            ], default: 'run'))
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

        $availability = $this->availabilitySummary($preview);
        if ($availability !== null) {
            note($availability);
        }

        $previewNotes = [];
        foreach ($preview->warnings as $message) {
            $previewNotes[] = '• '.rtrim($message, ". \t\n\r\0\x0B").'.';
        }
        if ($previewNotes !== []) {
            warning("Preview notes:\n".implode("\n", $previewNotes));
        }

        note($this->pricingSummary($preview));
    }

    private function showPlainPreview(PendingResearch $pending, ResearchPreview $preview): void
    {
        $this->line('Research preview');
        $this->line('Request ID: '.$pending->requestId);
        $this->line('Profiles: '.$preview->profiles->pluck('id')->implode(', '));
        $this->line('Fallbacks: '.($preview->fallbacks->isEmpty() ? 'none' : $preview->fallbacks->pluck('id')->implode(', ')));
        $this->line($this->pricingSummary($preview));
    }

    private function writeResponse(ResearchResponse $response): int
    {
        if ($this->agentMode()) {
            $this->writeJson($response->toArray());
        } else {
            $this->components->info("Research {$response->status->value}. Request ID: {$response->requestId}");
            foreach ($response->results as $result) {
                if (is_string($result->content)) {
                    $this->writeMarkdown($result->content);
                } else {
                    $this->line(json_encode($result->content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
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

    private function writeMarkdown(string $markdown): void
    {
        $converter = new CommonMarkConverter([
            'allow_unsafe_links' => false,
            'html_input' => 'strip',
        ]);
        $html = $converter->convert($markdown)->getContent();

        foreach (range(1, 6) as $level) {
            $html = str_replace(
                ["<h{$level}>", "</h{$level}>"],
                ['<div class="font-bold text-green">', '</div>'],
                $html,
            );
        }

        $this->line(parse('<div class="space-y-1">'.$html.'</div>'));
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
            'pricing' => $this->pricingEnvelope($preview),
        ];
    }

    private function pricingSummary(ResearchPreview $preview): string
    {
        if ($preview->hardCostCeilingAvailable
            && $preview->maximumEstimatedCost !== null
            && $preview->currency !== null) {
            $amount = $preview->currency === 'USD'
                ? '$'.$preview->maximumEstimatedCost
                : $preview->maximumEstimatedCost.' '.$preview->currency;

            return "Estimated cost: {$amount} max";
        }

        return 'Estimated cost: unavailable';
    }

    private function availabilitySummary(ResearchPreview $preview): ?string
    {
        if ($preview->unavailable->isEmpty()) {
            return null;
        }

        /** @var array<string, list<string>> $grouped */
        $grouped = [];
        foreach ($preview->unavailable as $profile => $reasons) {
            $reason = $this->sentenceFragment($reasons);
            $grouped[$reason] ??= [];
            $grouped[$reason][] = $profile;
        }

        $details = collect($grouped)
            ->map(fn (array $profiles, string $reason): string => implode(', ', $profiles).' — '.lcfirst($reason))
            ->values()
            ->implode('; ');

        return 'Unavailable Profiles ('.$preview->unavailable->count().'): '.$details.'.';
    }

    /** @return array<string, mixed> */
    private function pricingEnvelope(ResearchPreview $preview): array
    {
        return [
            'schema_version' => 1,
            'status' => $preview->pricingStatus,
            'known_subtotals' => $preview->knownCostSubtotals
                ->map(fn (string $amount, string $currency): array => [
                    'amount' => $amount,
                    'currency' => $currency,
                ])
                ->values()
                ->all(),
            'hard_ceiling_available' => $preview->hardCostCeilingAvailable,
            'quotes' => $preview->pricingQuotes->map(function (array $entry): array {
                $quote = $entry['quote'];

                return [
                    'profile' => $entry['profile'],
                    'role' => $entry['role'],
                    'reachable_from' => $entry['reachable_from'],
                    'maximum_invocations' => $entry['maximum_invocations'],
                    'identity' => $entry['identity']?->toArray(),
                    'usage' => $entry['usage']?->toArray(),
                    'conservative_usage_bound' => $entry['conservative'],
                    'freshness' => [
                        'status' => $entry['freshness'],
                        'retrieved_at' => $quote->provenance?->retrievedAt?->format(DATE_ATOM),
                    ],
                    'quote' => [
                        'completeness' => $quote->completeness->value,
                        'cost' => $quote->cost?->toArray(),
                        'source' => $quote->source->value,
                        'missing_units' => $quote->missingUnits,
                        'snapshot_fingerprint' => $quote->snapshot?->fingerprint,
                        'provenance' => $quote->provenance?->toArray(),
                    ],
                ];
            })->values()->all(),
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

    private function defaultGroupName(): string
    {
        $group = config('librarium.default_group', 'quick');

        return is_string($group) ? $group : 'quick';
    }

    private function groupLabel(string $name): string
    {
        $group = $this->catalog->group($name);
        $ready = $group['profiles']->count();
        $total = $ready + count($group['unavailable']);

        return "{$name} group ({$ready}/{$total} ready)";
    }

    private function selectionInfo(string $value): string
    {
        if ($value === 'multiple') {
            return 'Choose any eligible Profiles for a custom fan-out.';
        }

        if (str_starts_with($value, 'profile:')) {
            $item = $this->catalog->find(substr($value, 8));

            return $item === null
                ? ''
                : "One {$item->profile->resultKind->value} result from {$item->profile->provider}.";
        }

        $name = $value === 'default' ? $this->defaultGroupName() : substr($value, 6);
        $description = match ($name) {
            'quick' => 'Fast grounded answers and search results.',
            'deep' => 'Long-form research reports.',
            'visibility' => 'AI answer-surface and visibility observations.',
            'all' => 'Every configured Profile.',
            default => 'An application-defined Profile group.',
        };

        return ($value === 'default' ? "Configured default [{$name}]. " : '').$description;
    }

    /** @param list<string> $sentences */
    private function sentenceFragment(array $sentences): string
    {
        return collect($sentences)
            ->map(fn (string $sentence): string => rtrim($sentence, ". \t\n\r\0\x0B"))
            ->implode('; ');
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
