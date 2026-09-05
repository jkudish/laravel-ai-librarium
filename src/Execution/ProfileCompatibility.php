<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Execution;

use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;

final readonly class ProfileCompatibility
{
    public function allows(Profile $primary, Profile $candidate): bool
    {
        return $primary->observation !== ObservationMode::SurfaceSnapshot
            && $candidate->resultKind === $primary->resultKind
            && $this->groundingStrength($candidate->grounding) >= $this->groundingStrength($primary->grounding)
            && $candidate->observation === $primary->observation
            && $primary->corpora->every(
                fn (Corpus $corpus): bool => $candidate->corpora->containsStrict($corpus),
            )
            && $primary->retrievalMethods->every(
                fn (RetrievalMethod $method): bool => $candidate->retrievalMethods->containsStrict($method),
            );
    }

    public function acceptsResult(Profile $profile, ResearchResult $result): bool
    {
        $provenance = $result->provenance;

        if ($profile->grounding === GroundingPolicy::Optional
            && $profile->resultKind === ResultKind::GroundedAnswer
            && $profile->observation === ObservationMode::ApiOutput
            && $provenance->resultKind === ResultKind::ModelAnswer) {
            return $result->citations->isEmpty()
                && $provenance->corpora->isEmpty()
                && $provenance->retrievalMethods->count() === 1
                && $provenance->retrievalMethods->containsStrict(RetrievalMethod::ModelOnly)
                && $provenance->observationMode !== ObservationMode::SurfaceSnapshot
                && $provenance->surface === null;
        }

        return $provenance->resultKind === $profile->resultKind
            && $this->sameValues($profile->corpora, $provenance->corpora)
            && $this->sameValues($profile->retrievalMethods, $provenance->retrievalMethods)
            && match ($profile->observation) {
                ObservationMode::ApiOutput => $provenance->observationMode !== ObservationMode::SurfaceSnapshot
                    && $provenance->surface === null,
                ObservationMode::SurfaceSnapshot => $provenance->observationMode === ObservationMode::SurfaceSnapshot
                    && $provenance->resultKind === ResultKind::SurfaceObservation
                    && $provenance->retrievalMethods->containsStrict(RetrievalMethod::SurfaceCollector)
                    && $provenance->collector !== null
                    && $provenance->surface !== null,
            };
    }

    /**
     * @template T of Corpus|RetrievalMethod
     *
     * @param  Collection<int, T>  $expected
     * @param  Collection<int, T>  $actual
     */
    private function sameValues(Collection $expected, Collection $actual): bool
    {
        return $expected->count() === $actual->count()
            && $expected->every(
                fn (Corpus|RetrievalMethod $value): bool => $actual->containsStrict($value),
            );
    }

    private function groundingStrength(GroundingPolicy $grounding): int
    {
        return match ($grounding) {
            GroundingPolicy::None => 0,
            GroundingPolicy::Optional => 1,
            GroundingPolicy::Required => 2,
        };
    }
}
