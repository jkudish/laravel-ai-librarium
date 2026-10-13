<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Contracts\Librarium;
use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Execution\RunRuntime;
use Jkudish\LaravelAiLibrarium\Jobs\RunResearch;
use Jkudish\LaravelAiLibrarium\Stores\CacheRunStore;
use Jkudish\LaravelAiLibrarium\Stores\DatabaseRunStore;
use Jkudish\LaravelAiLibrarium\Stores\FilesystemRunStore;
use Jkudish\LaravelAiLibrarium\Stores\MemoryRunStore;
use Jkudish\LaravelAiPricing\Contracts\CostResolver;
use Jkudish\LaravelAiPricing\Enums\CostCompleteness;
use Jkudish\LaravelAiPricing\Enums\PricingSource;
use Jkudish\LaravelAiPricing\ValueObjects\PricingObservation;
use LogicException;

final class LibrariumManager implements Librarium
{
    /** @var array<string, Closure(Container, array<string, mixed>): RunStore> */
    private array $extensions = [];

    private ?RunStore $resolvedStore = null;

    public function __construct(private readonly Repository $config, private readonly Container $container, private readonly SourceCatalog $catalog, private readonly CostResolver $pricing) {}

    public function research(string $question): PendingResearch
    {
        return new PendingResearch($question, $this);
    }

    public function find(string $id): ?Run
    {
        return $this->store()->find($id)?->snapshot();
    }

    public function refresh(string $id): Run
    {
        return $this->container->make(RunRuntime::class)->refresh($id);
    }

    /** @return array<string, mixed> */
    public function sources(): array
    {
        $sources = [];
        $engines = [];
        $selection = $this->catalog->selectionCatalog();
        foreach ($this->catalog->entries() as $id => $entry) {
            $item = ['id' => $id, 'name' => $entry['name'], 'configured' => $selection[$id]['configured'], 'variants' => $entry['variants'] ?? [], 'options' => $entry['options'] ?? [], 'prices' => []];
            foreach (['quick', 'deep'] as $depth) {
                if (! $item['configured'] || ($depth === 'deep' && ! $selection[$id]['deep']) || ! $selection[$id]['collector']) {
                    continue;
                }
                $profile = $this->catalog->profile($id, $depth);
                $item['prices'][$depth] = $this->estimate($profile);
            }
            if ($entry['kind'] === 'engine') {
                $item['observation'] = 'surface';
                $item['collectors'] = array_keys($entry['collectors'] ?? []);
                $engines[] = $item;
            } else {
                $sources[] = $item;
            }
        }

        return ['sources' => $sources, 'engines' => $engines, 'groups' => $this->groups()];
    }

    /** @param Closure(Container, array<string, mixed>): RunStore $factory */
    public function extendStore(string $name, Closure $factory): void
    {
        $this->extensions[$name] = $factory;
        $this->resolvedStore = null;
    }

    /** @param Closure(string, array<string, mixed>): Profile $factory */
    public function extendCollector(string $name, Closure $factory): void
    {
        $this->catalog->extendCollector($name, $factory);
    }

    public function store(): RunStore
    {
        if ($this->resolvedStore !== null) {
            return $this->resolvedStore;
        }
        $settings = $this->config->get('librarium.runs', []);
        if (! is_array($settings)) {
            throw new LogicException('Run storage configuration must be an array.');
        }
        /** @var array<string, mixed> $settings */
        $name = $settings['store'] ?? 'filesystem';
        if (! is_string($name)) {
            throw new LogicException('Run storage name must be a string.');
        }
        if (isset($this->extensions[$name])) {
            return $this->resolvedStore = ($this->extensions[$name])($this->container, $settings);
        }
        if (is_a($name, RunStore::class, true)) {
            $store = $this->container->make($name);
            if (! $store instanceof RunStore) {
                throw new LogicException('The configured class must implement RunStore.');
            }

            return $this->resolvedStore = $store;
        }
        $cacheName = $settings['cache_store'] ?? null;
        $cache = $this->container->make(CacheManager::class)->store(is_string($cacheName) ? $cacheName : null);
        if (! $cache instanceof \Illuminate\Cache\Repository) {
            throw new LogicException('Run stores require a Laravel cache repository.');
        }
        $disk = $settings['disk'] ?? null;
        $connection = $settings['connection'] ?? null;

        return $this->resolvedStore = match ($name) {
            'memory' => new MemoryRunStore,
            'filesystem' => new FilesystemRunStore(
                $this->container->make(FilesystemManager::class)->disk(is_string($disk) ? $disk : null),
                $cache,
                is_string($settings['path'] ?? null) ? $settings['path'] : 'librarium',
                ($settings['encrypt'] ?? true) === false ? null : $this->container->make(Encrypter::class),
                is_string($disk) ? $disk : 'default',
            ),
            'database' => new DatabaseRunStore($this->container->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null), is_string($settings['table'] ?? null) ? $settings['table'] : 'librarium_runs'),
            'cache' => new CacheRunStore($cache, is_int($settings['ttl'] ?? null) ? $settings['ttl'] : 604800),
            default => throw new LogicException("Unknown RunStore [{$name}]."),
        };
    }

    /** @param array<string, mixed> $request */
    public function plan(array $request): StoredRun
    {
        $wire = $request;
        $options = $wire['options'] ?? [];
        $wire['options'] = is_array($options) ? (object) array_map(fn (mixed $value): mixed => is_array($value) && $value === [] ? (object) [] : $value, $options) : $options;
        Spec::validate($wire, 'request');
        /** @var array{question: string, depth: string, only?: list<string>, skip: list<string>, max_cost?: string, options: array<string, array<string, mixed>>} $request */
        $maximum = $request['max_cost'] ?? $this->config->get('librarium.max_cost');
        if ($maximum !== null && (! is_string($maximum) || preg_match('/^(0|[1-9][0-9]*)(\.[0-9]+)?$/D', $maximum) !== 1)) {
            throw new LogicException('max_cost must be a non-negative USD decimal string.');
        }
        $catalog = $this->catalog->selectionCatalog();
        $groups = $this->groups();
        $preselection = SourceSelection::select($catalog, $groups, $request['depth'], $request['only'] ?? null, $request['skip']);
        $profiles = [];
        $estimates = [];
        foreach ($preselection['selected'] as $id) {
            $profiles[$id] = $this->catalog->profile($id, $request['depth'], $request['options'][$id] ?? []);
            $estimates[$id] = $this->estimate($profiles[$id]);
        }
        $selection = SourceSelection::select($catalog, $groups, $request['depth'], $request['only'] ?? null, $request['skip'], $maximum, $estimates);
        $results = [];
        $notices = [];
        foreach ($selection['selected'] as $id) {
            $profile = $profiles[$id];
            $settings = $this->catalog->settings($id);
            $collector = $settings['collector'] ?? null;
            $providerDeadline = is_a($profile->driver, Contracts\DeclaresDeadline::class, true) ? $profile->driver::deadlineSeconds($profile) : null;
            if ($providerDeadline !== null && $providerDeadline > $this->timeout()) {
                $notices[] = ['code' => 'wait_timeout', 'message' => "Source [{$id}] may run for {$providerDeadline} seconds, beyond librarium.timeout ({$this->timeout()}). Its saved handle remains recoverable with find()->refresh(); the provider may still finish and bill the task."];
            }
            $results[] = ['source' => $id, 'status' => 'queued', 'observation' => $catalog[$id]['engine'] ? 'surface' : 'api', 'answer' => null, 'citations' => [], 'cost' => ['estimated' => $estimates[$id], 'reported' => null], 'started_at' => null, 'duration_ms' => null, 'provenance' => ['provider' => $profile->provider, 'variant' => $profile->id, 'model' => $profile->model, 'collector' => is_string($collector) ? $collector : null], 'error' => null];
        }
        $run = Run::fromArray(['spec' => 'librarium.run/1', 'id' => 'run_'.Str::ulid(), 'question' => $request['question'], 'depth' => $request['depth'], 'status' => 'planned', 'created_at' => CarbonImmutable::now()->utc()->toIso8601ZuluString(), 'finished_at' => null, 'cost' => ['estimated' => $selection['estimated'], 'reported' => null, 'complete' => false, 'max' => $maximum], 'results' => $results, 'skipped' => $selection['skipped'], 'notices' => $notices]);

        return new StoredRun($run, ['request' => $request]);
    }

    /** @param array<string, mixed> $request */
    public function execute(array $request, bool $detach): Run
    {
        $doc = $this->create($request);
        $runtime = $this->container->make(RunRuntime::class);
        $deadline = CarbonImmutable::now()->addSeconds($this->timeout());
        $run = $runtime->start($doc->run->id());
        if (! $detach) {
            while ($run->status() === 'running' && CarbonImmutable::now()->lessThan($deadline)) {
                Sleep::for(1)->seconds();
                $run = $runtime->refresh($run->id());
            }
        }

        return $run;
    }

    /** @param array<string, mixed> $request */
    public function enqueue(array $request): QueuedResearchResponse
    {
        $doc = $this->create($request);

        return new QueuedResearchResponse($doc->run->id(), RunResearch::dispatch($doc->run->id()));
    }

    /**
     * @internal CLI creates a stored Run before installing its detach handler.
     *
     * @param  array<string, mixed>  $request
     */
    public function create(array $request): StoredRun
    {
        $plan = $this->plan($request);
        $document = $plan->run->toArray();
        $document['status'] = 'running';
        $run = new StoredRun(new Run($document), $plan->state);
        $version = $this->store()->save($run, null);
        Events\ResearchStarted::dispatch($run->run->id(), CarbonImmutable::now());

        return $run->withVersion($version);
    }

    public function timeout(): int
    {
        $timeout = $this->config->get('librarium.timeout', 6900);
        if (! is_int($timeout) || $timeout < 1 || $timeout > 86400) {
            throw new LogicException('timeout must be between 1 and 86400 seconds.');
        }

        return $timeout;
    }

    /** @return array<string, list<string>> */
    private function groups(): array
    {
        $groups = $this->config->get('librarium.groups', []);
        if (! is_array($groups)) {
            throw new LogicException('groups must map names to lists of source names.');
        }
        foreach ($groups as $name => $members) {
            if (! is_string($name) || ! is_array($members) || ! array_is_list($members)) {
                throw new LogicException('groups must map names to lists of source names.');
            }
            if (isset($this->catalog->entries()[$name])) {
                throw new Exceptions\SelectionException('group_name_conflict', $name);
            }
            foreach ($members as $member) {
                if (! is_string($member)) {
                    throw new LogicException('Group members must be source names.');
                }
            }
        }

        /** @var array<string, list<string>> $groups */
        return $groups;
    }

    private function estimate(Profile $profile): ?string
    {
        if (! $profile->pricingUsageConservative || $profile->pricingIdentity === null || $profile->pricingUsage === null) {
            return null;
        }
        $now = CarbonImmutable::now();
        $quote = $this->pricing->resolve(new PricingObservation($profile->pricingIdentity, $profile->pricingUsage, pricedAt: $now, pricedUntil: $now->addSeconds($this->timeout())));
        $fresh = in_array($quote->source, [PricingSource::Configured, PricingSource::ProviderReported], true)
            || ($quote->provenance?->retrievedAt !== null && $quote->provenance->retrievedAt >= $now->subDays(30));

        return $fresh && $quote->completeness === CostCompleteness::Complete && $quote->cost?->currency === 'USD' ? (string) $quote->cost->amount : null;
    }
}
