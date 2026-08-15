<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

final class ProfileCatalog
{
    /** @var list<string> */
    private const array QUICK = [
        'gemini-grounded',
        'openrouter-grounded',
        'brave-answers',
        'exa',
        'kagi-fastgpt',
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
                    credential: $this->nullableString($configuration, 'credential'),
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
            'profile' => ['array:driver,provider,model,result_kind,grounding,observation,corpora,retrieval_methods,prompt,enabled,options,credential,estimated_cost'],
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
            'profile.credential' => ['nullable', 'string', 'min:1'],
            'profile.estimated_cost' => ['nullable', 'array:amount,currency'],
            'profile.estimated_cost.amount' => ['required_with:profile.estimated_cost', 'decimal:0,6', 'min:0', 'lte:1000000'],
            'profile.estimated_cost.currency' => ['required_with:profile.estimated_cost', 'string', 'size:3', 'uppercase'],
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
        $estimatedCost = data_get($value, 'estimated_cost.amount');
        $currency = data_get($value, 'estimated_cost.currency');

        if (! is_bool($enabled)) {
            throw ValidationException::withMessages(['enabled' => 'The enabled value must be true or false.']);
        }

        if ($currency !== null && ! is_string($currency)) {
            throw ValidationException::withMessages(['estimated_cost.currency' => 'The currency must be a string.']);
        }

        return new Profile(
            id: $id,
            driver: $driver,
            provider: $provider,
            model: $model,
            resultKind: ResultKind::from($resultKind),
            grounding: GroundingPolicy::from($grounding),
            observation: ObservationMode::from($observation),
            corpora: collect($corpora)->map(fn (string $corpus): Corpus => Corpus::from($corpus)),
            retrievalMethods: collect($retrievalMethods)->map(fn (string $method): RetrievalMethod => RetrievalMethod::from($method)),
            prompt: $prompt,
            enabled: $enabled,
            options: $options,
            estimatedCostMicros: is_string($estimatedCost) || is_numeric($estimatedCost) ? $this->micros((string) $estimatedCost) : null,
            currency: $currency,
        );
    }

    /** @return list<string> */
    private function reasons(Profile $profile, bool $requiresCredential, ?string $credential): array
    {
        $reasons = [];

        if (! $profile->enabled) {
            $reasons[] = 'Profile is disabled.';
        }

        if ($requiresCredential && blank($credential)) {
            $reasons[] = 'Credential is not configured.';
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

    private function micros(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 1_000_000) + (int) str_pad(substr($fraction, 0, 6), 6, '0');
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
