<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Xai;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use stdClass;

/** Runs one inline xAI Responses API request with the web_search tool only. */
final readonly class XaiGrokDriver implements Driver
{
    private const string ENDPOINT = 'https://api.x.ai/v1/responses';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 3600;

    private const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    private const int MAX_UNIX_TIMESTAMP = 253_402_300_799;

    private const int USD_TICKS_PER_DOLLAR = 10_000_000_000;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        $hostname = '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/';

        return [
            'max_turns' => ['sometimes', 'integer', 'min:1'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1'],
            'allowed_domains' => ['sometimes', 'array', 'list', 'min:1', 'max:5', 'prohibits:options.excluded_domains'],
            'allowed_domains.*' => ['required', 'string', 'max:253', 'distinct:ignore_case', "regex:{$hostname}"],
            'excluded_domains' => ['sometimes', 'array', 'list', 'min:1', 'max:5', 'prohibits:options.allowed_domains'],
            'excluded_domains.*' => ['required', 'string', 'max:253', 'distinct:ignore_case', "regex:{$hostname}"],
            'enable_image_search' => ['sometimes', static function (string $attribute, mixed $value, callable $fail): void {
                if (! is_bool($value)) {
                    $fail("The {$attribute} field must be true or false.");
                }
            }],
            'enable_image_understanding' => ['sometimes', static function (string $attribute, mixed $value, callable $fail): void {
                if (! is_bool($value)) {
                    $fail("The {$attribute} field must be true or false.");
                }
            }],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->validateRequest($request);
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withToken((string) $request->profile->credential)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->post(self::ENDPOINT, $this->payload($request));
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException(
                    'xai.deadline_exceeded',
                    'The xAI request did not complete before the research deadline.',
                );
            }

            throw new DriverException(
                'xai.connection',
                'The xAI request did not return a response.',
            );
        }

        $this->remainingSeconds($request, 'The xAI response arrived after the research deadline.');

        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $json = $this->json($response);
        if (array_key_exists('error', $json) && $json['error'] !== null) {
            throw new DriverException('xai.invalid_response', 'xAI returned an unsuccessful response.');
        }

        return $this->result($request, $json);
    }

    private function validateRequest(DriverRequest $request): void
    {
        $profile = $request->profile;

        if (
            $profile->provider !== 'xai'
            || $profile->resultKind !== ResultKind::GroundedAnswer
            || $profile->grounding !== GroundingPolicy::Required
            || $profile->observation !== ObservationMode::ApiOutput
            || $profile->corpora->values()->all() !== [Corpus::Web]
            || $profile->retrievalMethods->values()->all() !== [RetrievalMethod::ModelSearchTool]
        ) {
            throw new DriverException(
                'xai.invalid_profile',
                'The xAI Grok Profile has incompatible provider or provenance semantics.',
                false,
            );
        }

        if (blank($profile->credential)) {
            throw new DriverException('xai.authentication', 'The xAI provider is not configured.');
        }

        if (blank($profile->model)) {
            throw new DriverException(
                'xai.invalid_profile',
                'The xAI Grok Profile must configure a model.',
                false,
            );
        }

        $this->remainingSeconds($request);
    }

    /** @return array<string, mixed> */
    private function payload(DriverRequest $request): array
    {
        $options = $request->profile->options;
        $tool = ['type' => 'web_search'];
        $filters = [];

        foreach (['allowed_domains', 'excluded_domains'] as $key) {
            $domains = data_get($options, $key);
            if (is_array($domains)) {
                $filters[$key] = array_map(
                    static function (mixed $domain): string {
                        if (! is_string($domain)) {
                            throw new DriverException(
                                'xai.invalid_options',
                                'The xAI Grok Profile received invalid domain options.',
                                false,
                            );
                        }

                        return strtolower(trim($domain));
                    },
                    array_values($domains),
                );
            }
        }

        if ($filters !== []) {
            $tool['filters'] = $filters;
        }

        foreach (['enable_image_search', 'enable_image_understanding'] as $key) {
            if (data_get($options, $key) === true) {
                $tool[$key] = true;
            }
        }

        $payload = [
            'model' => $request->profile->model,
            'input' => [[
                'role' => 'user',
                'content' => $request->prompt,
            ]],
            'tools' => [$tool],
        ];

        foreach (['max_turns', 'max_output_tokens'] as $key) {
            $value = data_get($options, $key);
            if (is_int($value)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /** @param array<array-key, mixed> $json */
    private function result(DriverRequest $request, array $json): ResearchResult
    {
        $content = $this->content($json);
        if ($content === '') {
            throw new DriverException('xai.invalid_response', 'xAI returned a response without output text.');
        }

        $usage = $this->usage(data_get($json, 'usage'));
        $model = $this->str(data_get($json, 'model')) ?? $request->profile->model;
        $completedAt = $this->completedAt($json);

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
                observedAt: $completedAt,
            ),
            citations: $this->citations($json),
            completedAt: $completedAt,
            model: $model,
            usage: $usage,
            providerMeta: $this->providerMeta(data_get($json, 'usage')),
        );
    }

    /** @param array<array-key, mixed> $json */
    private function content(array $json): string
    {
        $parts = [];

        foreach ($this->records(data_get($json, 'output')) as $item) {
            $contents = is_array(data_get($item, 'content'))
                ? $this->records(data_get($item, 'content'))
                : [$item];

            foreach ($contents as $part) {
                $type = data_get($part, 'type');
                if (is_string($type) && $type !== 'output_text') {
                    continue;
                }

                $text = data_get($part, 'text');
                if (is_string($text)) {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return Collection<int, Citation>
     */
    private function citations(array $json): Collection
    {
        /** @var list<Citation> $citations */
        $citations = [];
        $seen = [];

        foreach ($this->records(data_get($json, 'output')) as $item) {
            $contents = is_array(data_get($item, 'content'))
                ? $this->records(data_get($item, 'content'))
                : [$item];

            foreach ($contents as $part) {
                $type = data_get($part, 'type');
                if (is_string($type) && $type !== 'output_text') {
                    continue;
                }

                $text = is_string(data_get($part, 'text')) ? data_get($part, 'text') : '';
                foreach ($this->records(data_get($part, 'annotations')) as $annotation) {
                    if (data_get($annotation, 'type') !== 'url_citation') {
                        continue;
                    }

                    $url = $this->evidenceUrl(data_get($annotation, 'url'));
                    if ($url === null || isset($seen[$url])) {
                        continue;
                    }

                    $seen[$url] = true;
                    $citations[] = Citation::make(
                        derivation: CitationDerivation::ProviderReported,
                        source: new Source(
                            kind: SourceKind::WebPage,
                            url: $url,
                            title: $this->citationTitle($annotation, $text),
                        ),
                        locator: $this->locator($annotation),
                    );
                }
            }
        }

        return collect($citations);
    }

    /** @param array<array-key, mixed> $annotation */
    private function citationTitle(array $annotation, string $text): ?string
    {
        $title = $this->str(data_get($annotation, 'title'));
        if ($title !== null && preg_match('/^\d+$/', $title) !== 1) {
            return $title;
        }

        $start = data_get($annotation, 'start_index');
        if (! is_int($start) || $start < 0 || $start > Str::length($text)) {
            return null;
        }

        $preceding = trim(Str::substr($text, 0, $start));
        $fragments = preg_split('/[.!?\n]/u', $preceding);
        if (! is_array($fragments) || $fragments === []) {
            return null;
        }

        $candidate = trim((string) end($fragments));
        $candidate = trim((string) preg_replace('/\[\[\d+\]\]\([^)]*\)\s*$/u', '', $candidate));

        return Str::length($candidate) >= 8 ? Str::substr($candidate, 0, 160) : null;
    }

    /** @param array<array-key, mixed> $annotation */
    private function locator(array $annotation): ?string
    {
        $start = $this->nonNegativeInt(data_get($annotation, 'start_index'));
        $end = $this->nonNegativeInt(data_get($annotation, 'end_index'));

        if ($start === null && $end === null) {
            return null;
        }

        return 'characters:'.($start ?? '?').'-'.($end ?? '?');
    }

    private function usage(mixed $value): ?Usage
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new DriverException('xai.invalid_response', 'xAI returned invalid usage information.');
        }

        $input = $this->usageInt($value, 'input_tokens');
        $output = $this->usageInt($value, 'output_tokens');
        $this->usageInt($value, 'total_tokens');
        $ticks = $this->usageInt($value, 'cost_in_usd_ticks');

        return new Usage(
            promptTokens: $input,
            completionTokens: $output,
            actualCost: $ticks === null ? null : $this->ticksToUsd($ticks),
            currency: $ticks === null ? null : 'USD',
        );
    }

    private function providerMeta(mixed $usage): ?stdClass
    {
        if (! is_array($usage) || array_is_list($usage)) {
            return null;
        }

        $total = $this->usageInt($usage, 'total_tokens');

        return $total === null ? null : (object) ['total_tokens' => $total];
    }

    /** @param array<array-key, mixed> $usage */
    private function usageInt(array $usage, string $key): ?int
    {
        if (! array_key_exists($key, $usage)) {
            return null;
        }

        $value = $usage[$key];
        if (! is_int($value) || $value < 0 || $value > self::MAX_SAFE_INTEGER) {
            throw new DriverException('xai.invalid_response', 'xAI returned invalid usage information.');
        }

        return $value;
    }

    private function ticksToUsd(int $ticks): string
    {
        $whole = intdiv($ticks, self::USD_TICKS_PER_DOLLAR);
        $fraction = rtrim(str_pad((string) ($ticks % self::USD_TICKS_PER_DOLLAR), 10, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole.'.'.$fraction;
    }

    /** @param array<array-key, mixed> $json */
    private function completedAt(array $json): CarbonImmutable
    {
        $timestamp = data_get($json, 'completed_at');
        if ($timestamp === null) {
            return CarbonImmutable::now();
        }

        if (! is_int($timestamp) || $timestamp < 0 || $timestamp > self::MAX_UNIX_TIMESTAMP) {
            throw new DriverException('xai.invalid_response', 'xAI returned an invalid completion timestamp.');
        }

        return CarbonImmutable::createFromTimestampUTC($timestamp);
    }

    /** @return array<array-key, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw new DriverException('xai.invalid_response', 'xAI returned a malformed response.');
        }

        return $json;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 || $this->isKeyLikeBadRequest($response) => new DriverException('xai.authentication', 'xAI rejected the configured credential.'),
            $response->status() === 403 => new DriverException('xai.authorization', 'xAI denied this request.'),
            $response->status() === 429 => new DriverException('xai.rate_limited', 'xAI rate limited this request.'),
            $response->serverError() => new DriverException('xai.unavailable', 'xAI is temporarily unavailable.'),
            default => new DriverException('xai.invalid_request', 'xAI rejected this request.', false),
        };
    }

    private function isKeyLikeBadRequest(Response $response): bool
    {
        return $response->status() === 400
            && preg_match('/\b(?:api[ _-]?key|credential|authentication)\b/i', Str::limit($response->body(), 4096, '')) === 1;
    }

    private function remainingSeconds(DriverRequest $request, string $message = 'The xAI request could not start before the research deadline.'): int
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 1) {
            throw new DriverException('xai.deadline_exceeded', $message);
        }

        return max(1, (int) floor($seconds));
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) !== $value || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
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

    /** @return list<array<array-key, mixed>> */
    private function records(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
