<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\You;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
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

/** Laravel HTTP Driver for You.com's one-request Answer API. */
final readonly class YouAnswerDriver implements Driver
{
    private const string ENDPOINT = 'https://api.you.com/v1/answer';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int MAX_CITATION_EXCERPT_LENGTH = 8_192;

    /** @var list<string> */
    private const array COUNTRIES = [
        'AR', 'AU', 'AT', 'BE', 'BR', 'CA', 'CL', 'DK', 'FI', 'FR', 'DE', 'HK', 'IN', 'ID', 'IT', 'JP', 'KR', 'MY', 'MX',
        'NL', 'NZ', 'NO', 'CN', 'PL', 'PT', 'PH', 'RU', 'SA', 'ZA', 'ES', 'SE', 'CH', 'TW', 'TR', 'GB', 'US',
    ];

    /** @var list<string> */
    private const array LANGUAGES = [
        'AR', 'EU', 'BN', 'BG', 'CA', 'HR', 'CS', 'DA', 'NL', 'EN', 'EN-GB', 'ET', 'FI', 'FR', 'GL', 'DE', 'EL', 'GU', 'HE',
        'HI', 'HU', 'IS', 'IT', 'KN', 'KO', 'LV', 'LT', 'MS', 'ML', 'MR', 'NB', 'PL', 'PA', 'RO', 'RU', 'SR', 'SK', 'SL', 'ES',
        'SV', 'TA', 'TE', 'TH', 'TR', 'UK', 'VI',
    ];

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return self::optionRules();
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->preflight($request);

        $credential = $request->profile->credential;
        if (blank($credential)) {
            throw new DriverException(
                'you_answer.authentication',
                'The You.com Answer provider is not configured.',
            );
        }

        $response = $this->send($request, (string) $credential);
        $this->ensureResponseWithinDeadline($request);

        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw $this->invalidResponse();
        }

        return $this->result($request, $json);
    }

    private function preflight(DriverRequest $request): void
    {
        $profile = $request->profile;
        if ($profile->provider !== 'you-answer'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::GroundedAnswer
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException(
                'you_answer.invalid_profile',
                'The You.com Answer Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        $rules = ['query' => ['bail', 'required', 'string', 'max:400']];
        foreach (self::optionRules() as $key => $rule) {
            $rules['options.'.$key] = $rule;
        }

        try {
            Validator::make(
                ['query' => $request->prompt, 'options' => $request->profile->options],
                [
                    'options' => ['array:'.implode(',', self::optionNames())],
                    ...$rules,
                ],
            )->validate();
        } catch (ValidationException) {
            throw new DriverException(
                'you_answer.invalid_request',
                'The You.com Answer query or options are invalid.',
                false,
            );
        }

        if (trim($request->prompt) === '' || self::utf16Length($request->prompt) > 400) {
            throw new DriverException(
                'you_answer.invalid_request',
                'The You.com Answer query or options are invalid.',
                false,
            );
        }
    }

    private static function utf16Length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** @return array<string, mixed> */
    private static function optionRules(): array
    {
        $domain = ['bail', 'string', self::domainRule(...)];

        return [
            'freshness' => ['bail', 'sometimes', 'string', self::freshnessRule(...)],
            'country' => ['bail', 'sometimes', 'string', 'in:'.implode(',', self::COUNTRIES)],
            'language' => ['bail', 'sometimes', 'string', 'in:'.implode(',', self::LANGUAGES)],
            'include_domains' => ['bail', 'sometimes', 'array', 'min:1', 'max:500', 'prohibits:options.exclude_domains,options.boost_domains'],
            'include_domains.*' => $domain,
            'exclude_domains' => ['bail', 'sometimes', 'array', 'min:1', 'max:500'],
            'exclude_domains.*' => $domain,
            'boost_domains' => ['bail', 'sometimes', 'array', 'min:1', 'max:500'],
            'boost_domains.*' => $domain,
        ];
    }

    private static function domainRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $domain = trim($value);
        if (mb_strlen($domain) > 253
            || preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $domain) !== 1) {
            $fail('The domain must be a valid domain name.');
        }
    }

    /** @return list<string> */
    private static function optionNames(): array
    {
        return ['freshness', 'country', 'language', 'include_domains', 'exclude_domains', 'boost_domains'];
    }

    private static function freshnessRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (in_array($value, ['day', 'week', 'month', 'year'], true)) {
            return;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})to(\d{4}-\d{2}-\d{2})$/', $value, $matches) !== 1
            || ! self::isDate($matches[1])
            || ! self::isDate($matches[2])
            || $matches[1] > $matches[2]) {
            $fail('The freshness value must be a supported period or valid ascending date range.');
        }
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function send(DriverRequest $request, string $credential): Response
    {
        $remaining = $this->remainingSeconds($request);
        $options = $request->profile->options;
        foreach (['include_domains', 'exclude_domains', 'boost_domains'] as $key) {
            if (isset($options[$key]) && is_array($options[$key])) {
                /** @var list<string> $domains */
                $domains = $options[$key];
                $options[$key] = array_map(static fn (string $domain): string => strtolower(trim($domain)), $domains);
            }
        }

        try {
            $response = Http::withHeaders(['X-API-Key' => $credential])
                ->acceptJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout($remaining)
                ->post(self::ENDPOINT, [
                    'query' => $request->prompt,
                    ...$options,
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException(
                    'you_answer.deadline_exceeded',
                    'You.com Answer did not respond before the research deadline.',
                    false,
                );
            }

            throw new DriverException(
                'you_answer.connection',
                'You.com Answer could not be reached.',
            );
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw new DriverException(
                'you_answer.deadline_exceeded',
                'The You.com Answer request could not start before the research deadline.',
                false,
            );
        }

        return $seconds;
    }

    private function ensureResponseWithinDeadline(DriverRequest $request): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException(
                'you_answer.deadline_exceeded',
                'You.com Answer did not respond before the research deadline.',
                false,
            );
        }
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('you_answer.authentication', 'You.com Answer rejected the configured credential.'),
            $response->status() === 402 => new DriverException('you_answer.insufficient_credit', 'You.com Answer could not run because the account has insufficient API credit.'),
            $response->status() === 403 => new DriverException('you_answer.authorization', 'You.com Answer denied this request.'),
            $response->status() === 408 => new DriverException('you_answer.timeout', 'You.com Answer timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('you_answer.rate_limited', 'You.com Answer rate limited this request.'),
            $response->serverError() => new DriverException('you_answer.unavailable', 'You.com Answer is temporarily unavailable.'),
            default => new DriverException('you_answer.invalid_request', 'You.com Answer rejected this request.', false),
        };
    }

    /** @param array<array-key, mixed> $json */
    private function result(DriverRequest $request, array $json): ResearchResult
    {
        $answer = data_get($json, 'answer');
        $citationValues = data_get($json, 'citations');
        $resultValues = data_get($json, 'results.web');
        if (! is_string($answer) || trim($answer) === ''
            || ! is_array($citationValues) || ! array_is_list($citationValues)
            || ! is_array($resultValues) || ! array_is_list($resultValues)) {
            throw $this->invalidResponse();
        }

        [$citations, $citationEntries] = $this->citations($citationValues);
        $referenceCounts = $this->inlineReferenceCounts($answer, count($citationValues));
        foreach ($citationEntries as $index => &$entry) {
            $entry['inline_reference_count'] = $referenceCounts[$index + 1] ?? 0;
        }
        unset($entry);

        $considered = $this->consideredResults($resultValues);
        $completedAt = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $answer,
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
            providerMeta: (object) [
                'you-com:answer' => [
                    'observation' => 'api_output',
                    'citation_entries' => $citationEntries,
                    'considered_web_results' => $considered,
                ],
            ],
        );
    }

    /**
     * @param  list<mixed>  $values
     * @return array{Collection<int, Citation>, list<array{citation_number: int, source_url: string, excerpts: list<string>, inline_reference_count?: int}>}
     */
    private function citations(array $values): array
    {
        $citations = [];
        $entries = [];

        foreach ($values as $index => $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw $this->invalidResponse();
            }

            $url = $this->evidenceUrl(data_get($value, 'source'));
            $excerpts = data_get($value, 'excerpts');
            if ($url === null || ! is_array($excerpts) || ! array_is_list($excerpts)
                || array_any($excerpts, static fn (mixed $excerpt): bool => ! is_string($excerpt))) {
                throw $this->invalidResponse();
            }

            /** @var list<string> $nonblankExcerpts */
            $nonblankExcerpts = array_values(array_filter($excerpts, static fn (mixed $excerpt): bool => is_string($excerpt) && trim($excerpt) !== ''));
            $citations[] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(kind: SourceKind::WebPage, url: $url),
                excerpt: isset($nonblankExcerpts[0]) ? mb_substr($nonblankExcerpts[0], 0, self::MAX_CITATION_EXCERPT_LENGTH) : null,
                locator: '[['.($index + 1).']]',
            );
            $entries[] = [
                'citation_number' => $index + 1,
                'source_url' => $url,
                'excerpts' => $nonblankExcerpts,
            ];
        }

        return [collect($citations), $entries];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array<string, mixed>>
     */
    private function consideredResults(array $values): array
    {
        $results = [];

        foreach ($values as $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw $this->invalidResponse();
            }

            $url = $this->evidenceUrl(data_get($value, 'url'));
            $title = data_get($value, 'title');
            $snippets = data_get($value, 'snippets', []);
            if ($url === null
                || ($title !== null && ! is_string($title))
                || ! is_array($snippets) || ! array_is_list($snippets)
                || array_any($snippets, static fn (mixed $snippet): bool => ! is_string($snippet))) {
                throw $this->invalidResponse();
            }

            $result = ['url' => $url];
            foreach (['title', 'description', 'thumbnail_url', 'page_age'] as $field) {
                $fieldValue = data_get($value, $field);
                if ($fieldValue !== null && ! is_string($fieldValue)) {
                    throw $this->invalidResponse();
                }
                if (is_string($fieldValue) && trim($fieldValue) !== '') {
                    $result[$field] = $fieldValue;
                }
            }
            $result['snippets'] = array_values(array_filter($snippets, static fn (mixed $snippet): bool => is_string($snippet) && trim($snippet) !== ''));
            $results[] = $result;
        }

        return $results;
    }

    /** @return array<int, int> */
    private function inlineReferenceCounts(string $answer, int $citationCount): array
    {
        preg_match_all('/\[\[([\d\s,]+)\]\]/', $answer, $matches);
        $counts = [];

        foreach ($matches[1] as $reference) {
            foreach (explode(',', $reference) as $token) {
                $number = filter_var(trim($token), FILTER_VALIDATE_INT);
                if (! is_int($number) || $number < 1 || $number > $citationCount) {
                    throw $this->invalidResponse();
                }
                $counts[$number] = ($counts[$number] ?? 0) + 1;
            }
        }

        return $counts;
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

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
                ? $value
                : null;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'you_answer.invalid_response',
            'You.com Answer returned an invalid grounded answer.',
            false,
        );
    }
}
