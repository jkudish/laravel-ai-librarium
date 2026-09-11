<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Closure;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\CollectedResponse;
use Jkudish\LaravelAiLibrarium\Collection\ResponseRedactor;
use Jkudish\LaravelAiLibrarium\Collection\SensitiveCollection;
use Jkudish\LaravelAiLibrarium\Contracts\CollectsResearch;
use Jkudish\LaravelAiLibrarium\Contracts\ExecutesResearch;
use Jkudish\LaravelAiLibrarium\Contracts\RecoversResearch;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;

final class PendingResearch
{
    private ?string $selector = null;

    /** @var string|list<string>|array<string, mixed>|null */
    private string|array|null $selection = null;

    /** @var list<string> */
    private array $fallbackProfiles = [];

    private ?string $maximumCost = null;

    private ?string $maximumCostCurrency = null;

    private ?int $timeoutSeconds = null;

    /**
     * @param  Closure(): ExecutesResearch  $resolveExecutor
     * @param  Closure(): CollectsResearch  $resolveCollector
     * @param  Closure(): RecoversResearch  $resolveRecovery
     */
    public function __construct(
        public readonly string $input,
        public readonly string $requestId,
        private readonly ValidationFactory $validator,
        private readonly ResearchCompiler $compiler,
        private readonly Closure $resolveExecutor,
        private readonly Closure $resolveCollector,
        private readonly Closure $resolveRecovery,
        private readonly ResponseRedactor $redactor,
    ) {}

    public function using(string $profile): self
    {
        $this->validateProfileId($profile, 'using');

        return $this->select('using', $profile);
    }

    public function group(string $group): self
    {
        $this->validator->make(['group' => $group], ['group' => ['required', 'string', 'min:1']])->validate();

        return $this->select('group', $group);
    }

    /** @param list<string> $profiles */
    public function fanOut(array $profiles): self
    {
        $this->validateProfileIds($profiles, 'fanOut');

        return $this->select('fanOut', $profiles);
    }

    /**
     * @param  list<Corpus>  $corpora
     * @param  list<RetrievalMethod>  $retrievalMethods
     */
    public function matching(
        ?ResultKind $resultKind = null,
        ?GroundingPolicy $grounding = null,
        ?ObservationMode $observation = null,
        array $corpora = [],
        array $retrievalMethods = [],
    ): self {
        if ($resultKind === null && $grounding === null && $observation === null && $corpora === [] && $retrievalMethods === []) {
            throw ValidationException::withMessages(['matching' => 'At least one matching requirement is required.']);
        }

        return $this->select('matching', compact('resultKind', 'grounding', 'observation', 'corpora', 'retrievalMethods'));
    }

    /** @param list<string> $profiles */
    public function fallbacks(array $profiles): self
    {
        $this->validateProfileIds($profiles, 'fallbacks');
        $this->fallbackProfiles = $profiles;

        return $this;
    }

    public function maxCost(int|float|string $amount, string $currency = 'USD'): self
    {
        $this->validator->make(
            ['amount' => $amount, 'currency' => $currency],
            [
                'amount' => ['required', 'decimal:0,6', 'gt:0', 'lte:1000000'],
                'currency' => ['required', 'string', 'size:3', 'uppercase'],
            ],
        )->validate();

        $this->maximumCost = (string) $amount;
        $this->maximumCostCurrency = $currency;

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->validator->make(['seconds' => $seconds], ['seconds' => ['integer', 'min:1', 'max:86400']])->validate();
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    public function preview(): ResearchPreview
    {
        return $this->compiler->compile($this)->preview;
    }

    public function run(): ResearchResponse
    {
        return ($this->resolveExecutor)()->run($this->compiler->compile($this));
    }

    public function collect(): CollectedResponse
    {
        $plan = $this->compiler->compile($this);
        $policy = $this->redactor->prepare($plan);

        return $this->redactor->redact(($this->resolveCollector)()->collect($plan), $policy);
    }

    public function collectSensitive(): SensitiveCollection
    {
        return ($this->resolveCollector)()->collect($this->compiler->compile($this));
    }

    public function submit(bool $capture = true): DurableOutcome
    {
        if ($this->selector !== 'using' || $this->fallbackProfiles !== []) {
            throw ValidationException::withMessages([
                'selector' => 'Durable submission requires using() with one exact Profile and no fallbacks.',
            ]);
        }

        return ($this->resolveRecovery)()->submit($this->compiler->compile($this), $capture);
    }

    public function queue(): QueuedResearchResponse
    {
        return ($this->resolveExecutor)()->queue($this->compiler->compile($this));
    }

    /** @return array{kind: string|null, value: string|list<string>|array<string, mixed>|null} */
    public function selector(): array
    {
        return ['kind' => $this->selector, 'value' => $this->selection];
    }

    /** @return list<string> */
    public function fallbackProfiles(): array
    {
        return $this->fallbackProfiles;
    }

    /** @return array{amount: string, currency: string}|null */
    public function maximumCost(): ?array
    {
        if ($this->maximumCost === null || $this->maximumCostCurrency === null) {
            return null;
        }

        return ['amount' => $this->maximumCost, 'currency' => $this->maximumCostCurrency];
    }

    public function timeoutSeconds(): ?int
    {
        return $this->timeoutSeconds;
    }

    /** @param string|list<string>|array<string, mixed> $value */
    private function select(string $selector, string|array $value): self
    {
        if ($this->selector !== null) {
            throw ValidationException::withMessages([
                'selector' => "The [{$this->selector}] selector is already set; [{$selector}] cannot also be used.",
            ]);
        }

        $this->selector = $selector;
        $this->selection = $value;

        return $this;
    }

    private function validateProfileId(string $profile, string $path): void
    {
        $this->validator->make([$path => $profile], [$path => ['required', 'string', 'min:1']])->validate();
    }

    /** @param list<string> $profiles */
    private function validateProfileIds(array $profiles, string $path): void
    {
        $this->validator->make(
            [$path => $profiles],
            [$path => ['required', 'array', 'list', 'min:1'], $path.'.*' => ['required', 'string', 'min:1', 'distinct']],
        )->validate();
    }
}
