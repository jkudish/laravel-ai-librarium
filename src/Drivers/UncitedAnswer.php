<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Profile;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;

/**
 * A complete answer that cites nothing is still an answer. Like the Laravel AI
 * mapper, it is recorded as an ungrounded model answer: model_only retrieval,
 * no corpora and zero citations, whatever the Profile's grounding policy.
 *
 * @internal
 */
final class UncitedAnswer
{
    public static function provenance(Profile $profile, bool $cited, CarbonImmutable $observedAt): ResultProvenance
    {
        if ($cited) {
            return new ResultProvenance($profile->resultKind, $profile->retrievalMethods, $profile->corpora, $observedAt);
        }

        /** @var Collection<int, RetrievalMethod> $modelOnly */
        $modelOnly = collect([RetrievalMethod::ModelOnly]);

        return new ResultProvenance(ResultKind::ModelAnswer, $modelOnly, $profile->corpora->take(0), $observedAt);
    }
}
