<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Exa;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\Responses\Usage;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

/**
 * A focused Laravel HTTP Driver for the Exa Search API.
 *
 * Only validated result fields become terminal content. Credentials, response
 * bodies, and provider error text never enter results or Driver exceptions.
 */
final readonly class ExaSearchDriver implements DefinesSpendingBound, Driver
{
    private const string ENDPOINT = 'https://api.exa.ai/search';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $this->requireCredential($request);
        $json = $this->requestJson($request);
        $results = $this->results($json);
        /** @var Collection<int, Citation> $citations */
        $citations = collect();
        $parts = [];
        $seen = [];

        foreach ($results as $result) {
            $url = $this->evidenceUrl(data_get($result, 'url'));
            if ($url === null || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $reportedTitle = $this->str(data_get($result, 'title'));
            $title = $this->markdownInline($reportedTitle ?? 'Untitled');
            $text = $this->str(data_get($result, 'text'));
            $publishedDate = $this->str(data_get($result, 'publishedDate'));

            $parts[] = "### [{$title}]({$this->markdownUrl($url)})";
            if ($publishedDate !== null) {
                $parts[] = '*Published: '.$this->markdownInline($publishedDate).'*';
            }
            if ($text !== null) {
                $parts[] = '';
                $parts[] = $this->markdownText($text);
            }
            $parts[] = '';

            $citations->push(Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(
                    kind: SourceKind::WebPage,
                    url: $url,
                    title: $reportedTitle,
                ),
                excerpt: $text === null ? null : mb_substr($text, 0, 200),
            ));
        }

        $now = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : implode("\n", $parts),
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
            usage: $this->usage(data_get($json, 'costDollars.total')),
            providerMeta: (object) ['result_count' => count($results)],
        );
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        if ($request->profile->id !== 'exa' || $request->profile->options !== []) {
            return SpendingBound::unknown();
        }

        return SpendingBound::billableQuantities(
            new ModelIdentity('exa', 'search'),
            new PricingUsage(['requests' => 1, 'additional_results' => 0, 'summary_pages' => 0]),
        );
    }

    /** @return array<array-key, mixed> */
    private function requestJson(DriverRequest $request): array
    {
        $response = $this->send($request);
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException('exa.deadline_exceeded', 'Exa did not respond before the research deadline.');
        }

        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw new DriverException('exa.invalid_response', 'Exa returned a malformed response.');
        }

        if (filled(data_get($json, 'error'))) {
            throw new DriverException('exa.provider', 'Exa reported an error for this request.');
        }

        return $json;
    }

    private function send(DriverRequest $request): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $response = Http::withHeaders(['x-api-key' => (string) $request->profile->credential])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->post(self::ENDPOINT, [
                    'query' => $request->prompt,
                    'type' => 'auto',
                    'numResults' => 10,
                    'contents' => [
                        'text' => ['maxCharacters' => 2000],
                    ],
                ]);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw new DriverException('exa.deadline_exceeded', 'Exa did not respond before the research deadline.');
            }

            throw new DriverException('exa.connection', 'Exa could not be reached.');
        }

        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 408 => new DriverException('exa.timeout', 'Exa timed out before completing this request.'),
            $response->status() === 401 => new DriverException('exa.authentication', 'Exa rejected the configured credential.'),
            $response->status() === 402 => new DriverException('exa.insufficient_credits', 'The Exa account has insufficient credits.'),
            $response->status() === 403 => new DriverException('exa.authorization', 'Exa denied this request.'),
            $response->status() === 429 => new DriverException('exa.rate_limited', 'Exa rate limited this request.'),
            $response->status() >= 500 => new DriverException('exa.unavailable', 'Exa is temporarily unavailable.'),
            default => new DriverException('exa.invalid_request', 'Exa rejected this request.', false),
        };
    }

    private function requireCredential(DriverRequest $request): void
    {
        if (blank($request->profile->credential)) {
            throw new DriverException('exa.authentication', 'The Exa provider is not configured.');
        }
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($seconds < 0.001) {
            throw new DriverException('exa.deadline_exceeded', 'The Exa request could not start before the research deadline.');
        }

        return $seconds;
    }

    /**
     * @param  array<array-key, mixed>  $json
     * @return list<array<array-key, mixed>>
     */
    private function results(array $json): array
    {
        $results = data_get($json, 'results');
        if (! is_array($results) || ! array_is_list($results)) {
            throw new DriverException('exa.invalid_response', 'Exa returned a malformed response.');
        }

        return array_values(array_filter($results, static fn (mixed $result): bool => is_array($result) && ! array_is_list($result)));
    }

    private function evidenceUrl(mixed $value): ?string
    {
        if (
            ! is_string($value)
            || trim($value) !== $value
            || $value === ''
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $value : null;
    }

    private function usage(mixed $cost): ?Usage
    {
        if ((! is_int($cost) && ! is_float($cost)) || $cost < 0 || (is_float($cost) && ! is_finite($cost))) {
            return null;
        }

        $actualCost = is_int($cost)
            ? (string) $cost
            : $this->decimal($cost);

        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/', $actualCost) !== 1) {
            return null;
        }

        return new Usage(actualCost: $actualCost, currency: 'USD');
    }

    private function decimal(float $value): string
    {
        $encoded = strtolower((string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        if (! str_contains($encoded, 'e')) {
            return rtrim(rtrim($encoded, '0'), '.');
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?e([+-]?\d+)$/', $encoded, $matches) !== 1) {
            return '';
        }

        $integer = $matches[1];
        $fraction = rtrim($matches[2], '0');
        $exponent = (int) $matches[3];
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + $exponent;

        if ($decimalPosition <= 0) {
            return '0.'.str_repeat('0', -$decimalPosition).$digits;
        }

        if ($decimalPosition >= strlen($digits)) {
            return $digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
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

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
