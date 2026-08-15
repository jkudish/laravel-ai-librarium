<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium;

use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;

final class Profile
{
    /**
     * @param  Collection<int, Corpus>  $corpora
     * @param  Collection<int, RetrievalMethod>  $retrievalMethods
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly string $id,
        public readonly string $driver,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly ResultKind $resultKind,
        public readonly GroundingPolicy $grounding,
        public readonly ObservationMode $observation,
        public readonly Collection $corpora,
        public readonly Collection $retrievalMethods,
        public readonly string $prompt,
        public readonly bool $enabled,
        public readonly array $options,
        public readonly ?string $credential = null,
        public readonly ?int $estimatedCostMicros = null,
        public readonly ?string $currency = null,
    ) {}

    public function renderPrompt(string $input): string
    {
        return str_replace('{{ query }}', $input, $this->prompt);
    }
}
