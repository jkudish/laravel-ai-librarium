<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\LaravelAiDriver;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage;

final class ProfileCatalog
{
    /** @var list<string> */
    private const array QUICK = [
        'gemini-grounded',
        'openrouter-grounded',
        'brave-answers',
        'exa',
        'kagi-fastgpt',
        'parallel-turbo',
    ];

    /** @var list<string> */
    private const array VISIBILITY = [
        'searchapi-chatgpt',
        'searchapi-gemini',
        'searchapi-perplexity',
        'searchapi-google-ai-mode',
        'searchapi-bing-copilot',
        'searchapi-google-ai-overview',
        'perplexity-sonar-pro',
        'gemini-grounded',
        'grok',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ValidationFactory $validator,
        private readonly ProfileOptionValidator $optionValidator,
    ) {}

    /** @return Collection<string, ProfileAvailability> */
    public function all(): Collection
    {
        $configured = $this->config->get('librarium.profiles', []);
        if (! is_array($configured)) {
            throw ValidationException::withMessages(['profiles' => 'The profiles configuration must be an array.']);
        }

        $items = [];
        foreach ($configured as $id => $value) {
            if (! is_string($id) || ! is_array($value)) {
                throw ValidationException::withMessages(['profiles' => 'Every Profile must have a string ID and array configuration.']);
            }

            $this->validator->make(
                ['id' => $id],
                ['id' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9._-]*$/']],
            )->validate();

            $configuration = $this->associativeArray($value, "profiles.{$id}");
            $profile = $this->profile($id, $configuration);
            $items[$id] = new ProfileAvailability(
                $profile,
                $this->reasons(
                    profile: $profile,
                    requiresCredential: array_key_exists('credential', $configuration),
                ),
            );
        }

        return collect($items);
    }

    public function find(string $id): ?ProfileAvailability
    {
        return $this->all()->get($id);
    }

    /**
     * @return array{profiles: Collection<int, Profile>, unavailable: array<string, list<string>>}
     */
    public function group(string $group): array
    {
        $members = match ($group) {
            'quick' => self::QUICK,
            'visibility' => self::VISIBILITY,
            'deep' => $this->all()
                ->filter(fn (ProfileAvailability $item): bool => $item->profile->resultKind === ResultKind::ResearchReport)
                ->map(fn (ProfileAvailability $item): string => $item->profile->id)
                ->values()
                ->all(),
            'all' => $this->all()->map(fn (ProfileAvailability $item): string => $item->profile->id)->values()->all(),
            default => $this->customGroup($group),
        };

        return $this->resolve(array_values($members));
    }

    /**
     * @param  list<Corpus>  $corpora
     * @param  list<RetrievalMethod>  $retrievalMethods
     * @return array{profiles: Collection<int, Profile>, unavailable: array<string, list<string>>}
     */
    public function matching(
        ?ResultKind $resultKind,
        ?GroundingPolicy $grounding,
        ?ObservationMode $observation,
        array $corpora,
        array $retrievalMethods,
    ): array {
        $matches = $this->all()->filter(function (ProfileAvailability $item) use ($resultKind, $grounding, $observation, $corpora, $retrievalMethods): bool {
            $profile = $item->profile;

            return ($resultKind === null || $profile->resultKind === $resultKind)
                && ($grounding === null || $profile->grounding === $grounding)
                && ($observation === null || $profile->observation === $observation)
                && collect($corpora)->every(fn (Corpus $corpus): bool => $profile->corpora->containsStrict($corpus))
                && collect($retrievalMethods)->every(fn (RetrievalMethod $method): bool => $profile->retrievalMethods->containsStrict($method));
        });

        return $this->resolve(array_values($matches->map(fn (ProfileAvailability $item): string => $item->profile->id)->all()));
    }

    /**
     * @param  list<string>  $ids
     * @return array{profiles: Collection<int, Profile>, unavailable: array<string, list<string>>}
     */
    public function resolve(array $ids): array
    {
        $catalog = $this->all();
        $profiles = [];
        $unavailable = [];

        foreach (array_values(array_unique($ids)) as $id) {
            $item = $catalog->get($id);
            if ($item === null) {
                $unavailable[$id] = ['Profile is not configured.'];
            } elseif (! $item->isEligible()) {
                $unavailable[$id] = $item->reasons;
            } else {
                $profiles[] = $item->profile;
            }
        }

        return ['profiles' => collect($profiles), 'unavailable' => $unavailable];
    }

    /** @param array<string, mixed> $value */
    private function profile(string $id, array $value): Profile
    {
        $this->validator->make(['profile' => $value], [
            'profile' => ['array:driver,provider,model,result_kind,grounding,observation,corpora,retrieval_methods,prompt,enabled,options,allow_provider_routing,credential,pricing'],
            'profile.driver' => ['required', 'string', 'min:1'],
            'profile.provider' => ['required', 'string', 'min:1'],
            'profile.model' => ['nullable', 'string', 'min:1'],
            'profile.result_kind' => ['required', Rule::enum(ResultKind::class)],
            'profile.grounding' => ['required', Rule::enum(GroundingPolicy::class)],
            'profile.observation' => ['required', Rule::enum(ObservationMode::class)],
            'profile.corpora' => ['present', 'array', 'list'],
            'profile.corpora.*' => [Rule::enum(Corpus::class)],
            'profile.retrieval_methods' => ['present', 'array', 'list'],
            'profile.retrieval_methods.*' => [Rule::enum(RetrievalMethod::class)],
            'profile.prompt' => ['required', 'string', 'regex:/\{\{ query \}\}/'],
            'profile.enabled' => ['sometimes', 'boolean'],
            'profile.options' => ['sometimes', 'array'],
            'profile.allow_provider_routing' => ['sometimes', 'boolean'],
            'profile.credential' => ['nullable', 'string', 'min:1'],
            'profile.pricing' => ['nullable', 'array:provider,model,usage,conservative'],
            'profile.pricing.provider' => ['required_with:profile.pricing', 'string', 'min:1'],
            'profile.pricing.model' => ['required_with:profile.pricing', 'string', 'min:1'],
            'profile.pricing.usage' => ['required_with:profile.pricing', 'array', 'min:1'],
            'profile.pricing.conservative' => ['required_with:profile.pricing', 'boolean'],
        ])->validate();

        $driver = $this->string($value, 'driver');
        $provider = $this->string($value, 'provider');
        $model = $this->nullableString($value, 'model');
        $resultKind = $this->string($value, 'result_kind');
        $grounding = $this->string($value, 'grounding');
        $observation = $this->string($value, 'observation');
        $corpora = $this->stringList(data_get($value, 'corpora'), 'corpora');
        $retrievalMethods = $this->stringList(data_get($value, 'retrieval_methods'), 'retrieval_methods');
        $prompt = $this->string($value, 'prompt');
        $enabled = data_get($value, 'enabled', true);
        $options = $this->associativeArray(data_get($value, 'options', []), 'options');
        $allowProviderRouting = data_get($value, 'allow_provider_routing', false);
        $credential = $this->nullableString($value, 'credential');
        $pricing = data_get($value, 'pricing');

        if (! is_bool($enabled)) {
            throw ValidationException::withMessages(['enabled' => 'The enabled value must be true or false.']);
        }

        if (! is_bool($allowProviderRouting)) {
            throw ValidationException::withMessages(['allow_provider_routing' => 'The allow provider routing value must be true or false.']);
        }

        $pricingIdentity = null;
        $pricingUsage = null;
        $pricingUsageConservative = false;

        if ($pricing !== null) {
            $pricing = $this->associativeArray($pricing, 'pricing');
            $pricingIdentity = new ModelIdentity(
                $this->pricingIdentityComponent($pricing, 'provider'),
                $this->pricingIdentityComponent($pricing, 'model'),
            );
            $usage = $this->associativeArray(data_get($pricing, 'usage'), 'pricing.usage');

            foreach ($usage as $unit => $quantity) {
                if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $unit) !== 1
                    || (is_int($quantity)
                        ? $quantity < 0
                        : (! is_string($quantity) || preg_match('/^(?:0|[1-9]\\d*)(?:\\.\\d{1,18})?$/D', $quantity) !== 1))) {
                    throw ValidationException::withMessages([
                        'pricing.usage' => 'Usage must contain snake-case units with non-negative integer or decimal-string quantities.',
                    ]);
                }
            }

            $pricingUsage = new Usage($usage);
            $pricingUsageConservative = data_get($pricing, 'conservative') === true;
        }

        $resultKindValue = ResultKind::from($resultKind);
        $groundingPolicy = GroundingPolicy::from($grounding);
        $observationMode = ObservationMode::from($observation);
        $corpusValues = collect($corpora)->map(fn (string $corpus): Corpus => Corpus::from($corpus));
        $retrievalMethodValues = collect($retrievalMethods)->map(fn (string $method): RetrievalMethod => RetrievalMethod::from($method));

        if ($driver === LaravelAiDriver::class
            && $groundingPolicy === GroundingPolicy::Optional
            && data_get($options, 'web_search', true) === false) {
            $resultKindValue = ResultKind::ModelAnswer;
            $groundingPolicy = GroundingPolicy::None;
            $corpusValues = $this->emptyCorpora();
            $retrievalMethodValues = $this->modelOnlyRetrievalMethods();
        }

        $surfaceSemantics = $resultKindValue === ResultKind::SurfaceObservation
            || $retrievalMethodValues->containsStrict(RetrievalMethod::SurfaceCollector)
            || $observationMode === ObservationMode::SurfaceSnapshot;
        if ($surfaceSemantics && ($resultKindValue !== ResultKind::SurfaceObservation
            || $observationMode !== ObservationMode::SurfaceSnapshot
            || $retrievalMethodValues->count() !== 1
            || ! $retrievalMethodValues->containsStrict(RetrievalMethod::SurfaceCollector))) {
            throw ValidationException::withMessages([
                'profile' => 'Surface Profiles must use surface_observation, surface_snapshot, and only surface_collector retrieval.',
            ]);
        }

        return new Profile(
            id: $id,
            driver: $driver,
            provider: $provider,
            model: $model,
            resultKind: $resultKindValue,
            grounding: $groundingPolicy,
            observation: $observationMode,
            corpora: $corpusValues,
            retrievalMethods: $retrievalMethodValues,
            prompt: $prompt,
            enabled: $enabled,
            options: $options,
            allowProviderRouting: $allowProviderRouting,
            credential: $credential,
            pricingIdentity: $pricingIdentity,
            pricingUsage: $pricingUsage,
            pricingUsageConservative: $pricingUsageConservative,
        );
    }

    /** @return list<string> */
    private function reasons(Profile $profile, bool $requiresCredential): array
    {
        $reasons = [];

        if (! $profile->enabled) {
            $reasons[] = 'Profile is disabled.';
        }

        if ($requiresCredential && blank($profile->credential)) {
            $reasons[] = 'Credential is not configured.';
        }

        if ($requiresCredential
            && is_string($profile->credential)
            && preg_match('/[\x00-\x1F\x7F]/', $profile->credential) === 1) {
            $reasons[] = 'Credential contains invalid control characters.';
        }

        if ($profile->driver === LaravelAiDriver::class
            && $profile->grounding === GroundingPolicy::Required
            && data_get($profile->options, 'web_search', true) === false) {
            $reasons[] = 'Required-grounding Profiles must enable web search.';
        }

        if ($profile->driver === LaravelAiDriver::class
            && $profile->provider === 'openrouter'
            && data_get($profile->options, 'web_search', true) !== false
            && ! array_key_exists('search_engine', $profile->options)) {
            $reasons[] = 'OpenRouter web search requires an explicit search_engine option.';
        }

        if ($profile->driver === LaravelAiDriver::class
            && $profile->provider !== 'openrouter'
            && array_key_exists('search_engine', $profile->options)) {
            $reasons[] = 'The search_engine option is supported only by OpenRouter Profiles.';
        }

        $reasons = [...$reasons, ...$this->optionValidator->errors($profile->driver, $profile->options)];

        return $reasons;
    }

    /** @return list<string> */
    private function customGroup(string $group): array
    {
        if (! str_starts_with($group, 'custom:')) {
            throw ValidationException::withMessages([
                'group' => "Unknown group [{$group}]. Use quick, deep, visibility, all, or custom:<name>.",
            ]);
        }

        $members = $this->config->get('librarium.groups.'.$group);
        if (! is_array($members) || ! array_is_list($members)) {
            throw ValidationException::withMessages(['group' => "Custom group [{$group}] is not configured."]);
        }

        return $this->stringList($members, 'group');
    }

    /** @return Collection<int, RetrievalMethod> */
    private function modelOnlyRetrievalMethods(): Collection
    {
        return collect($this->modelOnlyRetrievalMethodValues());
    }

    /** @return Collection<int, Corpus> */
    private function emptyCorpora(): Collection
    {
        return collect()
            ->filter(static fn (mixed $value): bool => $value instanceof Corpus)
            ->values();
    }

    /** @return list<RetrievalMethod> */
    private function modelOnlyRetrievalMethodValues(): array
    {
        return [RetrievalMethod::ModelOnly];
    }

    /** @param array<string, mixed> $value */
    private function string(array $value, string $key): string
    {
        $item = data_get($value, $key);
        if (! is_string($item)) {
            throw ValidationException::withMessages([$key => 'The value must be a string.']);
        }

        return $item;
    }

    /** @param array<string, mixed> $value */
    private function nullableString(array $value, string $key): ?string
    {
        $item = data_get($value, $key);
        if ($item === null) {
            return null;
        }

        if (! is_string($item)) {
            throw ValidationException::withMessages([$key => 'The value must be a string.']);
        }

        return $item;
    }

    /** @param array<string, mixed> $value */
    private function pricingIdentityComponent(array $value, string $key): string
    {
        $component = $this->string($value, $key);

        if ($component !== trim($component) || preg_match('/[\x00-\x1F\x7F]/', $component) === 1) {
            throw ValidationException::withMessages([
                "pricing.{$key}" => 'Pricing identity values must be trimmed and contain no control characters.',
            ]);
        }

        return $component;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw ValidationException::withMessages([$path => 'The value must be a list.']);
        }

        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw ValidationException::withMessages([$path => 'Every value must be a string.']);
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value, string $path): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([$path => 'The value must be an array.']);
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw ValidationException::withMessages([$path => 'Every key must be a string.']);
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
