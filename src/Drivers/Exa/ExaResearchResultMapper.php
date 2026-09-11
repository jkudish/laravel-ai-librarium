<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Exa;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Recovery\RecoveryRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use stdClass;
use Throwable;

/** Maps a completed Exa Agent run without retaining its private provider handle. */
final readonly class ExaResearchResultMapper
{
    /** @param array<array-key, mixed> $run */
    public function map(DriverRequest|RecoveryRequest $request, array $run): ResearchResult
    {
        $output = data_get($run, 'output');
        if (! is_array($output) || array_is_list($output)) {
            throw $this->invalidResponse();
        }

        [$contentFormat, $content] = $this->content($output);
        $citations = $this->citations(data_get($output, 'grounding', []));
        $completedAt = $this->completedAt(data_get($run, 'completedAt'));
        $usage = $this->numbers(data_get($run, 'usage'));
        $reportedCosts = data_get($run, 'costDollars');
        $costs = $this->numbers($reportedCosts);
        if ($reportedCosts !== null && ! array_key_exists('total', $costs)) {
            throw $this->invalidResponse();
        }
        $totalCost = $costs['total'] ?? null;
        $stopReason = data_get($run, 'stopReason');
        if ($stopReason !== null && (! is_string($stopReason)
            || ! in_array($stopReason, ['schema_satisfied', 'budget_reached', 'stopped', 'error', 'cancelled'], true))) {
            throw $this->invalidResponse();
        }

        $metadata = array_filter([
            'execution' => 'background',
            'resumability' => 'durable_provider_task',
            'effort' => $request->profile->options['effort'] ?? 'auto',
            'stop_reason' => $stopReason,
            'source_count' => $citations->count(),
            'usage' => $usage === [] ? null : $usage,
            'cost_breakdown_usd' => $costs === [] ? null : $costs,
        ], static fn (mixed $value): bool => $value !== null);

        return ResearchResult::make(
            contentFormat: $contentFormat,
            content: $content,
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $completedAt,
            ),
            citations: $citations,
            completedAt: $completedAt,
            usage: $totalCost === null ? null : new Usage(
                actualCost: $this->decimal($totalCost),
                currency: 'USD',
            ),
            providerMeta: (object) ['exa:research' => $metadata],
        );
    }

    /**
     * @param  array<array-key, mixed>  $output
     * @return array{ContentFormat, string|stdClass}
     */
    private function content(array $output): array
    {
        $text = data_get($output, 'text');
        if ($text !== null && ! is_string($text)) {
            throw $this->invalidResponse();
        }

        if (is_string($text) && trim($text) !== '') {
            return [ContentFormat::Markdown, $text];
        }

        $structured = data_get($output, 'structured');
        if (! $structured instanceof stdClass
            || ! $this->isJsonValue($structured)) {
            throw $this->invalidResponse();
        }

        return [ContentFormat::Json, $structured];
    }

    /** @return Collection<int, Citation> */
    private function citations(mixed $grounding): Collection
    {
        if (! is_array($grounding) || ! array_is_list($grounding)) {
            throw $this->invalidResponse();
        }

        $citations = [];
        $seen = [];

        foreach ($grounding as $group) {
            if (! is_array($group) || array_is_list($group)) {
                throw $this->invalidResponse();
            }

            $values = data_get($group, 'citations', []);
            if (! is_array($values) || ! array_is_list($values)) {
                throw $this->invalidResponse();
            }

            foreach ($values as $value) {
                if (! is_array($value) || array_is_list($value)) {
                    throw $this->invalidResponse();
                }

                $url = $this->evidenceUrl(data_get($value, 'url'));
                $title = data_get($value, 'title');
                $text = data_get($value, 'text');
                if ($url === null
                    || ($title !== null && (! is_string($title) || trim($title) === ''))
                    || ($text !== null && ! is_string($text))) {
                    throw $this->invalidResponse();
                }

                if (isset($seen[$url])) {
                    continue;
                }

                $seen[$url] = true;
                $excerpt = is_string($text) && trim($text) !== '' ? mb_substr($text, 0, 200) : null;
                $citations[] = Citation::make(
                    derivation: CitationDerivation::ProviderReported,
                    source: new Source(
                        kind: SourceKind::WebPage,
                        url: $url,
                        title: is_string($title) ? $title : null,
                    ),
                    excerpt: $excerpt,
                );
            }
        }

        return collect($citations);
    }

    private function completedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw $this->invalidResponse();
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw $this->invalidResponse();
        }
    }

    /** @return array<string, int|float> */
    private function numbers(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalidResponse();
        }

        $numbers = [];
        foreach (['total', 'agentComputeUnits', 'searches', 'emails', 'phoneNumbers', 'agentCompute', 'search'] as $key) {
            $number = data_get($value, $key);
            if ($number === null) {
                continue;
            }
            if ((! is_int($number) && ! is_float($number))
                || $number < 0
                || (is_float($number) && ! is_finite($number))) {
                throw $this->invalidResponse();
            }

            $numbers[match ($key) {
                'agentComputeUnits' => 'agent_compute_units',
                'phoneNumbers' => 'phone_numbers',
                'agentCompute' => 'agent_compute',
                default => $key,
            }] = $number;
        }

        return $numbers;
    }

    private function evidenceUrl(mixed $value): ?string
    {
        return EvidenceUrl::sanitize($value);
    }

    private function decimal(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $encoded = strtolower((string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        if (! str_contains($encoded, 'e')) {
            $decimal = rtrim(rtrim($encoded, '0'), '.');

            return $this->validDecimal($decimal);
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?e([+-]?\d+)$/', $encoded, $matches) !== 1) {
            throw $this->invalidResponse();
        }

        $integer = $matches[1];
        $fraction = rtrim($matches[2], '0');
        $exponent = (int) $matches[3];
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            return $this->validDecimal('0.'.str_repeat('0', -$decimalPosition).$digits);
        }

        if ($decimalPosition >= strlen($digits)) {
            return $this->validDecimal($digits.str_repeat('0', $decimalPosition - strlen($digits)));
        }

        return $this->validDecimal(substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition));
    }

    private function validDecimal(string $value): string
    {
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/', $value) !== 1) {
            throw $this->invalidResponse();
        }

        return $value;
    }

    private function isJsonValue(mixed $value): bool
    {
        if (is_string($value) || is_int($value) || is_bool($value) || $value === null) {
            return true;
        }

        if (is_float($value)) {
            return is_finite($value);
        }

        $values = $value instanceof stdClass ? get_object_vars($value) : $value;

        return is_array($values)
            && array_all($values, fn (mixed $item): bool => $this->isJsonValue($item));
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'exa_research.invalid_response',
            'Exa Research returned an invalid completed report.',
            false,
        );
    }
}
