<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Valyu;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;
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

/** Focused Laravel HTTP Driver for Valyu Search. */
final readonly class ValyuSearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.valyu.ai/v1/search';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const float REQUEST_TIMEOUT_SECONDS = 30.0;

    private const array COUNTRY_CODES = [
        'ALL', 'AR', 'AU', 'AT', 'BE', 'BR', 'CA', 'CL', 'DK', 'FI', 'FR', 'DE', 'HK', 'IN', 'ID', 'IT', 'JP', 'KR',
        'MY', 'MX', 'NL', 'NZ', 'NO', 'CN', 'PL', 'PT', 'PH', 'RU', 'SA', 'ZA', 'ES', 'SE', 'CH', 'TW', 'TR', 'GB', 'US',
    ];

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'max_results' => ['sometimes', 'integer', 'between:1,20'],
            'search_type' => ['sometimes', 'string', 'in:all,web,proprietary,news'],
            'max_price' => ['sometimes', 'numeric', 'gt:0'],
            'relevance_threshold' => ['sometimes', 'numeric', 'between:0,1'],
            'included_sources' => ['sometimes', 'array', 'list', 'min:1', 'prohibits:options.excluded_sources'],
            'included_sources.*' => ['string', 'filled'],
            'excluded_sources' => ['sometimes', 'array', 'list', 'min:1', 'prohibits:options.included_sources'],
            'excluded_sources.*' => ['string', 'filled'],
            'source_biases' => ['sometimes', 'array', static function (string $attribute, mixed $value, callable $fail): void {
                if (is_array($value) && array_is_list($value)) {
                    $fail("The {$attribute} field must be an object.");
                }
            }],
            'source_biases.*' => ['integer', 'between:-5,5'],
            'instructions' => ['sometimes', 'string', 'filled', 'max:500'],
            'is_tool_call' => ['sometimes', static function (string $attribute, mixed $value, callable $fail): void {
                if (! is_bool($value)) {
                    $fail("The {$attribute} field must be true or false.");
                }
            }],
            'response_length' => ['sometimes', static function (string $attribute, mixed $value, callable $fail): void {
                if (! (is_int($value) && $value > 0) && ! in_array($value, ['short', 'medium', 'large', 'max'], true)) {
                    $fail("The {$attribute} field is invalid.");
                }
            }],
            'start_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:options.end_date'],
            'end_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:options.start_date'],
            'country_code' => ['sometimes', 'string', 'in:'.implode(',', self::COUNTRY_CODES)],
            'fast_mode' => ['sometimes', static function (string $attribute, mixed $value, callable $fail): void {
                if (! is_bool($value)) {
                    $fail("The {$attribute} field must be true or false.");
                }
            }],
            'url_only' => ['sometimes', static function (string $attribute, mixed $value, callable $fail): void {
                if (! is_bool($value)) {
                    $fail("The {$attribute} field must be true or false.");
                }
            }],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $response = $this->send($request);
        $this->remainingSeconds($request, 'Valyu Search did not respond before the research deadline.');

        if (! in_array($response->status(), [200, 206], true)) {
            throw $this->httpFailure($response);
        }

        $json = $this->json($response);
        if (data_get($json, 'success') !== true) {
            throw new DriverException('valyu_search.provider', 'Valyu Search reported an error for this request.');
        }

        $results = data_get($json, 'results');
        if (! is_array($results) || ! array_is_list($results)) {
            throw $this->invalidResponse();
        }

        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $parts = [];
        $seen = [];

        foreach ($results as $result) {
            if (! is_array($result) || array_is_list($result)) {
                continue;
            }

            $url = ValyuSupport::evidenceUrl(data_get($result, 'url'));
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $title = ValyuSupport::string(data_get($result, 'title'));
            $content = ValyuSupport::string(data_get($result, 'content'))
                ?? ValyuSupport::string(data_get($result, 'description'));
            $source = ValyuSupport::string(data_get($result, 'source'));
            $publishedAt = ValyuSupport::timestamp(data_get($result, 'publication_date'));
            $providerReference = ValyuSupport::providerReference(data_get($result, 'id'));

            $parts[] = '### ['.$this->markdownInline($title ?? $url).']('.$this->markdownUrl($url).')';
            if ($source !== null) {
                $parts[] = '*Source: '.$this->markdownInline($source).'*';
            }
            if (($request->profile->options['url_only'] ?? false) !== true && $content !== null) {
                $parts[] = '';
                $parts[] = $this->markdownText($content);
            }
            $parts[] = '';

            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: $this->sourceKind(data_get($result, 'source_type'), data_get($request->profile->options, 'search_type')),
                    url: $url,
                    providerReference: $providerReference,
                    title: $title,
                    publisher: $source,
                    publishedAt: $publishedAt,
                ),
                excerpt: $content === null ? null : mb_substr($content, 0, 500),
            ));
        }

        $now = CarbonImmutable::now();

        $result = ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : rtrim(implode("\n", $parts)),
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: $citations,
            completedAt: $now,
            usage: ValyuSupport::usage(data_get($json, 'total_deduction_dollars')),
            providerMeta: (object) [
                'result_count' => $citations->count(),
                'reported_result_count' => count($results),
                'warning_count' => $this->warningCount(data_get($json, 'warnings')),
            ],
        );

        if (str_contains(json_encode($result->toArray(), JSON_THROW_ON_ERROR), (string) $request->profile->credential)) {
            throw $this->invalidResponse();
        }

        return $result;
    }

    private function validateRequest(DriverRequest $request): void
    {
        $profile = $request->profile;
        $expectedCorpora = match (true) {
            ($profile->options['fast_mode'] ?? false) === true && ($profile->options['search_type'] ?? null) !== 'news' => [Corpus::Web],
            ($profile->options['search_type'] ?? null) === 'web' => [Corpus::Web],
            ($profile->options['search_type'] ?? null) === 'news' => [Corpus::News],
            ($profile->options['search_type'] ?? null) === 'proprietary' => [Corpus::Specialized],
            default => [Corpus::Web, Corpus::Specialized],
        };

        if ($profile->id !== 'valyu-search'
            || $profile->provider !== 'valyu'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== $expectedCorpora
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException('valyu_search.invalid_profile', 'The Valyu Search Profile has incompatible provider or provenance semantics.', false);
        }

        $options = $profile->options;
        $validator = Validator::make(
            ['query' => $request->prompt, 'options' => $options],
            ['query' => ['required', 'string'], 'options' => ['array:'.implode(',', $this->optionNames())], ...$this->prefixedRules()],
        );
        $validator->after(function (LaravelValidator $validator) use ($options): void {
            $responseLength = $options['response_length'] ?? null;
            if ($responseLength !== null
                && ! (is_int($responseLength) && $responseLength > 0)
                && ! in_array($responseLength, ['short', 'medium', 'large', 'max'], true)) {
                $validator->errors()->add('options.response_length', 'The response length is invalid.');
            }
            if (($options['fast_mode'] ?? false) === true && ($options['search_type'] ?? null) === 'proprietary') {
                $validator->errors()->add('options.fast_mode', 'Fast mode cannot use proprietary search.');
            }
            if (($options['url_only'] ?? false) === true && ! in_array($options['search_type'] ?? null, ['web', 'news'], true)) {
                $validator->errors()->add('options.url_only', 'URL-only mode requires web or news search.');
            }
            if (($options['fast_mode'] ?? false) === true || ($options['url_only'] ?? false) === true) {
                foreach (['relevance_threshold', 'source_biases', 'instructions'] as $key) {
                    if (array_key_exists($key, $options)) {
                        $validator->errors()->add('options.'.$key, 'This option is unavailable when reranking is bypassed.');
                    }
                }
            }
        });

        if ($validator->fails()) {
            throw new DriverException('valyu_search.invalid_request', 'The Valyu Search query or options are invalid.', false);
        }
        if (trim($request->prompt) === '') {
            throw new DriverException('valyu_search.invalid_request', 'The Valyu Search query or options are invalid.', false);
        }
        if (blank($profile->credential)) {
            throw new DriverException('valyu_search.authentication', 'The Valyu provider is not configured.', false);
        }

        $this->remainingSeconds($request, 'The Valyu Search request could not start before the research deadline.');
    }

    /** @return list<string> */
    private function optionNames(): array
    {
        return array_values(array_unique(array_map(static fn (string $key): string => str($key)->before('.')->toString(), array_keys(self::profileOptionRules()))));
    }

    /** @return array<string, mixed> */
    private function prefixedRules(): array
    {
        $rules = [];
        foreach (self::profileOptionRules() as $key => $rule) {
            $rules['options.'.$key] = $rule;
        }

        return $rules;
    }

    private function send(DriverRequest $request): Response
    {
        $remaining = $this->remainingSeconds($request, 'The Valyu Search request could not start before the research deadline.');
        $options = $request->profile->options;

        $payload = ['query' => $request->prompt];
        foreach ([
            'max_results' => 'max_num_results', 'search_type' => 'search_type', 'max_price' => 'max_price',
            'relevance_threshold' => 'relevance_threshold', 'included_sources' => 'included_sources',
            'excluded_sources' => 'excluded_sources', 'source_biases' => 'source_biases', 'instructions' => 'instructions',
            'is_tool_call' => 'is_tool_call', 'response_length' => 'response_length', 'start_date' => 'start_date',
            'end_date' => 'end_date', 'country_code' => 'country_code', 'fast_mode' => 'fast_mode', 'url_only' => 'url_only',
        ] as $option => $field) {
            if (array_key_exists($option, $options)) {
                $payload[$field] = $options[$option];
            }
        }

        try {
            return Http::withHeaders(['X-API-Key' => (string) $request->profile->credential])
                ->acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->post(self::ENDPOINT, $payload);
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('valyu_search.deadline_exceeded', 'Valyu Search did not respond before the research deadline.');
            }

            throw new DriverException('valyu_search.connection', 'Valyu Search could not be reached.');
        }
    }

    private function remainingSeconds(DriverRequest $request, string $message): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw new DriverException('valyu_search.deadline_exceeded', $message);
        }

        return $seconds;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('valyu_search.authentication', 'Valyu rejected the configured credential.'),
            $response->status() === 402 => new DriverException('valyu_search.insufficient_credit', 'The Valyu account has insufficient API credit.'),
            $response->status() === 403 => new DriverException('valyu_search.authorization', 'Valyu denied this request.'),
            $response->status() === 408 => new DriverException('valyu_search.timeout', 'Valyu Search timed out while processing this request.'),
            $response->status() === 429 => new DriverException('valyu_search.rate_limited', 'Valyu Search rate limited this request.'),
            $response->serverError() => new DriverException('valyu_search.unavailable', 'Valyu Search is temporarily unavailable.'),
            default => new DriverException('valyu_search.invalid_request', 'Valyu Search rejected this request.', false),
        };
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        return $json;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('valyu_search.invalid_response', 'Valyu Search returned a malformed response.');
    }

    private function sourceKind(mixed $sourceType, mixed $searchType): SourceKind
    {
        return match (true) {
            $searchType === 'news' || $sourceType === 'news' => SourceKind::NewsArticle,
            $sourceType === 'video' => SourceKind::Video,
            $sourceType === 'forum' => SourceKind::ForumPost,
            default => SourceKind::WebPage,
        };
    }

    private function warningCount(mixed $warnings): int
    {
        return is_array($warnings) && array_is_list($warnings)
            ? count(array_filter($warnings, static fn (mixed $warning): bool => ValyuSupport::string($warning) !== null))
            : 0;
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
}
