<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Parallel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\GroundingPolicy;
use Jkudish\LaravelAiLibrarium\Profiles\Enums\ObservationMode;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;

/** Runs the Parallel Search and fixed-mode Turbo profiles. */
final readonly class ParallelSearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.parallel.ai/v1/search';

    public function __construct(private ParallelTransport $transport) {}

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return ParallelOptions::searchRules(false);
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $turbo = $this->validateProfile($request);
        $options = ParallelOptions::search($request->profile->options, $turbo);
        $response = $this->transport->post($request, self::ENDPOINT, $this->payload($request, $options, $turbo));

        if ($response->failed() || $response->redirect()) {
            throw $this->transport->failure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        return $this->result($request, $json, $turbo, $options);
    }

    private function validateProfile(DriverRequest $request): bool
    {
        $profile = $request->profile;
        $turbo = $profile->id === 'parallel-turbo';
        if ((! $turbo && $profile->id !== 'parallel-search')
            || $profile->provider !== 'parallel'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException(
                'parallel.invalid_profile',
                'The Parallel Search Profile has incompatible identity or provenance semantics.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException('parallel.authentication', 'The Parallel provider is not configured.');
        }
        if (trim($request->prompt) === '' || mb_strlen($request->prompt) > 20_000) {
            throw new DriverException('parallel.invalid_request', 'The Parallel Search input is invalid.', false);
        }
        $this->transport->remainingSeconds($request);

        return $turbo;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function payload(DriverRequest $request, array $options, bool $turbo): array
    {
        $advanced = [];
        foreach (['source_policy', 'fetch_policy'] as $key) {
            if (isset($options[$key])) {
                $advanced[$key] = $options[$key];
            }
        }
        if (isset($options['max_chars_per_result'])) {
            $advanced['excerpt_settings'] = ['max_chars_per_result' => $options['max_chars_per_result']];
        }
        foreach (['location', 'max_results'] as $key) {
            if (isset($options[$key])) {
                $advanced[$key] = $options[$key];
            }
        }

        return array_filter([
            'objective' => $options['objective'] ?? $request->prompt,
            'search_queries' => $options['search_queries'] ?? [$request->prompt],
            'mode' => $turbo ? 'turbo' : ($options['mode'] ?? null),
            'max_chars_total' => $options['max_chars_total'] ?? null,
            'advanced_settings' => $advanced === [] ? null : $advanced,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @param  array<string, mixed>  $options
     */
    private function result(DriverRequest $request, array $json, bool $turbo, array $options): ResearchResult
    {
        $searchId = $this->boundedString($json['search_id'] ?? null);
        $sessionId = $this->boundedString($json['session_id'] ?? null, 1000);
        $results = $json['results'] ?? null;
        $warnings = $json['warnings'] ?? null;
        $usage = $json['usage'] ?? null;
        if ($searchId === null || $sessionId === null || ! is_array($results) || ! array_is_list($results)
            || ($warnings !== null && (! is_array($warnings) || ! array_is_list($warnings)))
            || ($usage !== null && (! is_array($usage) || ! array_is_list($usage)))) {
            throw $this->invalidResponse();
        }

        $parts = [];
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        foreach ($results as $index => $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw $this->invalidResponse();
            }
            $url = $this->evidenceUrl($value['url'] ?? null);
            $titleValue = $value['title'] ?? null;
            $title = $this->optionalString($titleValue);
            $excerpts = $value['excerpts'] ?? null;
            $publishedDate = $value['publish_date'] ?? null;
            if ($url === null || ($titleValue !== null && $title === null)
                || ! is_array($excerpts) || ! array_is_list($excerpts)
                || array_any($excerpts, static fn (mixed $excerpt): bool => ! is_string($excerpt))
                || ($publishedDate !== null && (! is_string($publishedDate) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $publishedDate) !== 1))) {
                throw $this->invalidResponse();
            }

            $excerpt = $this->str($excerpts[0] ?? null);
            $label = $this->markdownInline($title ?? $url);
            $part = '### '.($index + 1).'. '.$label."\n".$this->markdownUrl($url);
            if ($excerpt !== null) {
                $part .= "\n\n".$this->markdownText($excerpt);
            }
            $parts[] = $part;
            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(kind: SourceKind::WebPage, url: $url, title: $title),
                excerpt: $excerpt === null ? null : mb_substr($excerpt, 0, 500),
            ));
        }

        $warningCount = $this->warningCount($warnings);
        $metering = $this->metering($usage);
        $now = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : implode("\n\n", $parts),
            requestedProfile: $request->requestedProfile->id,
            provider: 'parallel',
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: ResultKind::SearchResults,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: $citations,
            completedAt: $now,
            providerMeta: (object) ['parallel:search' => [
                'mode' => $turbo ? 'turbo' : ($options['mode'] ?? 'advanced'),
                'result_count' => count($results),
                'warning_count' => $warningCount,
                'metering' => $metering,
            ]],
        );
    }

    private function warningCount(mixed $warnings): int
    {
        if ($warnings === null) {
            return 0;
        }
        if (! is_array($warnings)) {
            throw $this->invalidResponse();
        }
        foreach ($warnings as $warning) {
            if (! is_array($warning) || array_is_list($warning)
                || ! in_array($warning['type'] ?? null, ['spec_validation_warning', 'input_validation_warning', 'warning'], true)
                || $this->str($warning['message'] ?? null) === null) {
                throw $this->invalidResponse();
            }
        }

        return count($warnings);
    }

    /** @return list<array{name: string, count: int}> */
    private function metering(mixed $usage): array
    {
        if ($usage === null) {
            return [];
        }
        if (! is_array($usage)) {
            throw $this->invalidResponse();
        }
        $metering = [];
        foreach ($usage as $item) {
            if (! is_array($item) || array_is_list($item)
                || ! is_string($item['name'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/iD', $item['name']) !== 1
                || ! is_int($item['count'] ?? null) || $item['count'] < 0) {
                throw $this->invalidResponse();
            }
            $metering[] = ['name' => $item['name'], 'count' => $item['count']];
        }

        return $metering;
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            $normalized = strtolower(str_replace(['-', '.'], '_', (string) $key));
            if (preg_match('/(?:^|_)(?:signature|sig|credential|token|secret|api_key|key)(?:_|$)/', $normalized) === 1) {
                return null;
            }
        }

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== '' ? $value : null;
    }

    private function optionalString(mixed $value): ?string
    {
        return $value === null || ! is_string($value) ? null : $this->str($value);
    }

    private function boundedString(mixed $value, int $max = 255): ?string
    {
        $value = $this->str($value);

        return $value !== null && strlen($value) <= $max ? $value : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function markdownInline(string $value): string
    {
        return $this->markdownText(str_replace(["\r", "\n"], ' ', $value));
    }

    private function markdownText(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|', '~', '=', '^', '$'],
            ['\\\\', '\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\(', '\\)', '\\#', '\\+', '\\-', '\\.', '\\!', '\\|', '\\~', '\\=', '\\^', '\\$'],
            $escaped,
        );
    }

    private function markdownUrl(string $url): string
    {
        return str_replace(['\\', '(', ')'], ['%5C', '%28', '%29'], $url);
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('parallel.invalid_response', 'Parallel returned an invalid Search response.');
    }
}
