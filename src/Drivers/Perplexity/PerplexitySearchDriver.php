<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Perplexity;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

/** Direct, synchronous access to Perplexity's raw Search API. */
final readonly class PerplexitySearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.perplexity.ai/search';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    private const int MAX_UPSTREAM_RESULTS = 100;

    /** @var list<string> */
    private const array LANGUAGES = [
        'aa', 'ab', 'ae', 'af', 'ak', 'am', 'an', 'ar', 'as', 'av', 'ay', 'az', 'ba', 'be', 'bg', 'bh', 'bi', 'bm', 'bn', 'bo', 'br', 'bs', 'ca', 'ce', 'ch', 'co', 'cr', 'cs', 'cu', 'cv', 'cy', 'da', 'de', 'dv', 'dz', 'ee', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'ff', 'fi', 'fj', 'fo', 'fr', 'fy', 'ga', 'gd', 'gl', 'gn', 'gu', 'gv', 'ha', 'he', 'hi', 'ho', 'hr', 'ht', 'hu', 'hy', 'hz', 'ia', 'id', 'ie', 'ig', 'ii', 'ik', 'io', 'is', 'it', 'iu', 'ja', 'jv', 'ka', 'kg', 'ki', 'kj', 'kk', 'kl', 'km', 'kn', 'ko', 'kr', 'ks', 'ku', 'kv', 'kw', 'ky', 'la', 'lb', 'lg', 'li', 'ln', 'lo', 'lt', 'lu', 'lv', 'mg', 'mh', 'mi', 'mk', 'ml', 'mn', 'mr', 'ms', 'mt', 'my', 'na', 'nb', 'nd', 'ne', 'ng', 'nl', 'nn', 'no', 'nr', 'nv', 'ny', 'oc', 'oj', 'om', 'or', 'os', 'pa', 'pi', 'pl', 'ps', 'pt', 'qu', 'rm', 'rn', 'ro', 'ru', 'rw', 'sa', 'sc', 'sd', 'se', 'sg', 'si', 'sk', 'sl', 'sm', 'sn', 'so', 'sq', 'sr', 'ss', 'st', 'su', 'sv', 'sw', 'ta', 'te', 'tg', 'th', 'ti', 'tk', 'tl', 'tn', 'to', 'tr', 'ts', 'tt', 'tw', 'ty', 'ug', 'uk', 'ur', 'uz', 've', 'vi', 'vo', 'wa', 'wo', 'xh', 'yi', 'yo', 'za', 'zh', 'zu',
    ];

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        $domain = ['bail', 'string', self::domainRule(...)];

        return [
            'max_results' => ['bail', 'sometimes', 'integer', 'between:1,20'],
            'country' => ['bail', 'sometimes', 'string', self::countryRule(...)],
            'search_language_filter' => ['bail', 'sometimes', 'array', 'min:1', 'max:20'],
            'search_language_filter.*' => ['bail', 'string', self::languageRule(...)],
            'search_domain_allowlist' => ['bail', 'sometimes', 'array', 'min:1', 'max:20', 'prohibits:options.search_domain_denylist'],
            'search_domain_allowlist.*' => $domain,
            'search_domain_denylist' => ['bail', 'sometimes', 'array', 'min:1', 'max:20', 'prohibits:options.search_domain_allowlist'],
            'search_domain_denylist.*' => $domain,
            'search_context_size' => ['bail', 'sometimes', 'string', 'in:low,medium,high', 'prohibits:options.max_tokens,options.max_tokens_per_page'],
            'max_tokens' => ['bail', 'sometimes', 'integer', 'between:1,1000000', 'prohibits:options.search_context_size'],
            'max_tokens_per_page' => ['bail', 'sometimes', 'integer', 'between:1,1000000', 'prohibits:options.search_context_size'],
            'additional_queries' => ['bail', 'sometimes', 'array', 'max:4'],
            'additional_queries.*' => ['bail', 'string', 'min:1', self::queryRule(...)],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->preflight($request);
        $response = $this->send($request);

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException('perplexity_search.deadline_exceeded', 'Perplexity Search did not respond before the research deadline.');
        }

        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json) || ! is_array(data_get($json, 'results')) || ! array_is_list(data_get($json, 'results'))) {
            throw $this->invalidResponse();
        }

        $id = data_get($json, 'id');
        if (! is_string($id) || trim($id) === '' || strlen($id) > 255) {
            throw $this->invalidResponse();
        }

        $enhanced = $request->profile->options !== [];
        $results = $this->results(data_get($json, 'results'), $enhanced);
        $citations = [];
        $parts = [];

        foreach ($results as $result) {
            $title = $result['title'] ?? 'Untitled';
            $parts[] = '- **['.$this->markdownInline($title).']('.$this->markdownUrl($result['url']).')**';
            if (isset($result['snippet'])) {
                $parts[] = '  '.$this->markdownText($this->limit($result['snippet'], $enhanced ? 2_000 : 300));
            }

            $citations[] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $result['url'],
                    title: $result['title'] ?? null,
                ),
                excerpt: isset($result['snippet']) ? $this->limit($result['snippet'], $enhanced ? 500 : 200) : null,
            );
        }

        $now = CarbonImmutable::now();
        $content = $parts === [] ? 'No results found.' : implode("\n", $parts);
        if ($enhanced) {
            $content = $this->limit($content, 64_000);
        }

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $content,
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: collect($citations),
            completedAt: $now,
            providerMeta: (object) ['result_count' => count($results)],
        );
    }

    private function preflight(DriverRequest $request): void
    {
        $profile = $request->profile;
        if ($profile->id !== 'perplexity-search'
            || $profile->provider !== 'perplexity'
            || $profile->model !== null
            || $profile->resultKind !== ResultKind::SearchResults
            || $profile->grounding !== GroundingPolicy::None
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::SearchEndpoint]
        ) {
            throw new DriverException('perplexity_search.invalid_profile', 'The Perplexity Search Profile has incompatible provider or provenance semantics.', false);
        }

        $rules = ['query' => ['bail', 'required', 'string']];
        foreach (self::profileOptionRules() as $key => $rule) {
            $rules['options.'.$key] = $rule;
        }

        try {
            Validator::make(
                ['query' => $request->prompt, 'options' => $profile->options],
                ['options' => ['array:'.implode(',', self::optionNames())], ...$rules],
            )->validate();
        } catch (ValidationException) {
            throw new DriverException('perplexity_search.invalid_request', 'The Perplexity Search query or options are invalid.', false);
        }

        if (trim($request->prompt) === '') {
            throw new DriverException('perplexity_search.invalid_request', 'The Perplexity Search query or options are invalid.', false);
        }

        if (blank($profile->credential)) {
            throw new DriverException('perplexity_search.authentication', 'The Perplexity Search provider is not configured.');
        }

        $this->remainingSeconds($request);
    }

    private function send(DriverRequest $request): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            return Http::withToken((string) $request->profile->credential)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->post(self::ENDPOINT, $this->requestBody($request));
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('perplexity_search.deadline_exceeded', 'Perplexity Search did not respond before the research deadline.');
            }

            throw new DriverException('perplexity_search.connection', 'Perplexity Search could not be reached.');
        }
    }

    /** @return array<string, mixed> */
    private function requestBody(DriverRequest $request): array
    {
        $options = $request->profile->options;
        $queries = [$request->prompt];
        $seen = [trim($request->prompt) => true];
        foreach ($this->stringListOption($options, 'additional_queries') as $query) {
            $query = trim($query);
            if (! isset($seen[$query])) {
                $seen[$query] = true;
                $queries[] = $query;
            }
        }

        $body = [
            'query' => count($queries) === 1 ? $queries[0] : $queries,
            'max_results' => $options['max_results'] ?? 10,
        ];

        $country = $options['country'] ?? null;
        if (is_string($country)) {
            $body['country'] = strtoupper(trim($country));
        }
        $contextSize = $options['search_context_size'] ?? null;
        if (is_string($contextSize)) {
            $body['search_context_size'] = $contextSize;
        }
        foreach (['max_tokens', 'max_tokens_per_page'] as $key) {
            $value = $options[$key] ?? null;
            if (is_int($value)) {
                $body[$key] = $value;
            }
        }
        $languages = $this->stringListOption($options, 'search_language_filter');
        if ($languages !== []) {
            $body['search_language_filter'] = array_map(static fn (string $language): string => strtolower(trim($language)), $languages);
        }
        $allowlist = $this->stringListOption($options, 'search_domain_allowlist');
        if ($allowlist !== []) {
            $body['search_domain_filter'] = array_map(trim(...), $allowlist);
        }
        $denylist = $this->stringListOption($options, 'search_domain_denylist');
        if ($denylist !== []) {
            $body['search_domain_filter'] = array_map(static fn (string $domain): string => '-'.trim($domain), $denylist);
        }

        return $body;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function results(array $values, bool $enhanced): array
    {
        $results = [];
        $seen = [];

        foreach (array_slice($values, 0, self::MAX_UPSTREAM_RESULTS) as $value) {
            if (! is_array($value) || array_is_list($value)) {
                continue;
            }

            $url = $this->evidenceUrl(data_get($value, 'url'));
            if ($url === null) {
                continue;
            }

            $key = $enhanced ? $this->normalizedUrl($url) : $url;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $title = $this->text(data_get($value, 'title'));
            $snippet = $this->text(data_get($value, 'snippet'));
            $result = ['url' => $url];
            if ($title !== null) {
                $result['title'] = $enhanced ? $this->limit($title, 512) : $title;
            }
            if ($snippet !== null) {
                $result['snippet'] = $enhanced ? $this->limit($snippet, 2_000) : $snippet;
            }
            $results[] = $result;
        }

        return $results;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('perplexity_search.authentication', 'Perplexity Search rejected the configured credential.'),
            $response->status() === 402 => new DriverException('perplexity_search.billing', 'Perplexity Search could not bill this request.'),
            $response->status() === 403 => new DriverException('perplexity_search.plan_required', 'Perplexity Search denied this request for the configured plan.'),
            $response->status() === 408 => new DriverException('perplexity_search.timeout', 'Perplexity Search timed out while processing this request.'),
            $response->status() === 429 => new DriverException('perplexity_search.rate_limited', 'Perplexity Search rate limited this request.'),
            $response->serverError() => new DriverException('perplexity_search.unavailable', 'Perplexity Search is temporarily unavailable.'),
            default => new DriverException('perplexity_search.invalid_request', 'Perplexity Search rejected this request.', false),
        };
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 0.001) {
            throw new DriverException('perplexity_search.deadline_exceeded', 'The Perplexity Search request could not start before the research deadline.');
        }

        return $seconds;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('perplexity_search.invalid_response', 'Perplexity Search returned a malformed response.');
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) !== $value || $value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);

        return $parts !== false
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ($parts['host'] ?? '') !== ''
                ? $value
                : null;
    }

    private function normalizedUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return strtolower(rtrim($url, '/'));
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        parse_str($parts['query'] ?? '', $query);
        $query = array_filter($query, static function (mixed $_, int|string $key): bool {
            $key = strtolower((string) $key);

            return ! str_starts_with($key, 'utm_') && ! in_array($key, ['ref', 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid'], true);
        }, ARRAY_FILTER_USE_BOTH);
        $normalized = $host.($parts['path'] ?? '');

        return rtrim($normalized.($query === [] ? '' : '?'.http_build_query($query)), '/');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function stringListOption(array $options, string $key): array
    {
        $value = $options[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private function limit(string $value, int $length): string
    {
        return mb_substr($value, 0, $length);
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

    /** @return list<string> */
    private static function optionNames(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
            array_keys(self::profileOptionRules()),
        )));
    }

    private static function countryRule(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^[A-Za-z]{2}$/D', trim($value)) !== 1 || ! in_array(strtoupper(trim($value)), self::countries(), true)) {
            $fail("The {$attribute} field must be an ISO 3166-1 alpha-2 code.");
        }
    }

    private static function languageRule(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || ! in_array(strtolower(trim($value)), self::LANGUAGES, true)) {
            $fail("The {$attribute} field must be an ISO 639-1 language code.");
        }
    }

    private static function domainRule(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 253) {
            $fail("The {$attribute} field must contain 1 to 253 characters.");
        }
    }

    private static function queryRule(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            $fail("The {$attribute} field must not be empty.");
        }
    }

    /** @return list<string> */
    private static function countries(): array
    {
        return explode(' ', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW');
    }
}
