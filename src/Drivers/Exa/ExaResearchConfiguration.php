<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Exa;

/**
 * Reviewed Exa Agent research levels with fixed native per-run caps.
 * Sources/access date and limits: docs/history/exa-parallel-research-bounds.md.
 * A revision is immutable; changing any request field requires a new revision.
 */
final class ExaResearchConfiguration
{
    public const string REVISION = '2026-10-09';

    /**
     * Metered efforts only: Exa accepts `budget.maxCostDollars` for `auto`
     * and `ultra`, and `budget.maxDurationSeconds` for `ultra` alone.
     */
    public const array LEVELS = [
        'auto' => ['effort' => 'auto', 'max_cost_dollars' => 1],
        'ultra' => ['effort' => 'ultra', 'max_cost_dollars' => 5, 'max_duration_seconds' => 3_000],
    ];

    /**
     * @return array<string, array{
     *     driver: class-string<ExaResearchDriver>, provider: string, model: null,
     *     result_kind: string, grounding: string, observation: string,
     *     corpora: list<string>, retrieval_methods: list<string>, prompt: string,
     *     enabled: bool, credential: mixed,
     *     options: array{configuration: string, effort: string, max_cost_dollars: int, max_duration_seconds?: int}
     * }>
     */
    public static function profiles(): array
    {
        $profiles = [];
        foreach (self::LEVELS as $level => $limits) {
            $profiles['exa-research-'.$level] = [
                'driver' => ExaResearchDriver::class,
                'provider' => 'exa',
                'model' => null,
                'result_kind' => 'research_report',
                'grounding' => 'required',
                'observation' => 'api_output',
                'corpora' => ['web'],
                'retrieval_methods' => ['research_agent'],
                'prompt' => '{{ query }}',
                'enabled' => false,
                'options' => ['configuration' => 'research-'.$level.'-'.self::REVISION, ...$limits],
                // Consumers supply credentials when registering the
                // templates; request definitions also load without an app.
                'credential' => null,
            ];
        }

        return $profiles;
    }

    /**
     * The reviewed template frozen under this configuration, if any.
     *
     * @return array{options: array<string, mixed>, prompt: string}|null
     */
    public static function matching(mixed $configuration): ?array
    {
        foreach (self::profiles() as $profile) {
            if ($profile['options']['configuration'] === $configuration) {
                return $profile;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function configurations(): array
    {
        return array_map(
            static fn (string $level): string => 'research-'.$level.'-'.self::REVISION,
            array_keys(self::LEVELS),
        );
    }
}
