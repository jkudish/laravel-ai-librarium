<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Serply;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Contracts\DefinesSpendingBound;
use Jkudish\LaravelAiLibrarium\Contracts\Driver;
use Jkudish\LaravelAiLibrarium\Drivers\EvidenceUrl;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;
use Jkudish\LaravelAiLibrarium\SpendingBound;
use Jkudish\LaravelAiPricing\ValueObjects\ModelIdentity;
use Jkudish\LaravelAiPricing\ValueObjects\Usage as PricingUsage;

/**
 * Runs one Serply Google Search (GET /v1/search).
 *
 * Results drift when the location is unpinned, so `gl` is always sent. Pages
 * can repeat links, so results are deduplicated by link and trimmed to `num`.
 * Serply bills only successful uncached requests, so one transient 502 is
 * retried once; every other failure is final.
 *
 * Not live-verified: no Serply call has been made with this driver.
 */
final readonly class SerplyDriver implements DefinesSpendingBound, Driver
{
    private const string URL = 'https://api.serply.io/v1/search/';

    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    private const int REQUEST_TIMEOUT_SECONDS = 60;

    private const int MAX_BODY_BYTES = 4_000_000;

    private const int MAX_STRING_BYTES = 2_000;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'gl' => ['bail', 'sometimes', 'string', 'regex:/^[a-z]{2}$/D'],
            'hl' => ['bail', 'sometimes', 'string', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D'],
            'num' => ['bail', 'sometimes', 'integer:strict', 'min:1', 'max:10'],
            'device' => ['bail', 'sometimes', 'string', 'in:desktop,mobile'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        [$query, $device, $num] = $this->preflight($request);

        for ($attempt = 0; ; $attempt++) {
            $response = $this->send($request, $query, $device);
            if ($response->status() !== 502 || $attempt > 0) {
                break;
            }
        }

        if ($response->failed() || $response->redirect()) {
            throw $this->httpFailure($response);
        }
        if (strlen($response->body()) > self::MAX_BODY_BYTES) {
            throw $this->invalidResponse();
        }
        $json = $response->json();
        if (! is_array($json) || array_is_list($json) || ! is_array($json['results'] ?? null) || ! array_is_list($json['results'])) {
            throw $this->invalidResponse();
        }

        return TerminalRedaction::guard($this->result($request, $json['results'], $num), [(string) $request->profile->credential], $this->invalidResponse());
    }

    public function spendingBound(DriverRequest $request): SpendingBound
    {
        return SpendingBound::billableQuantities(new ModelIdentity('serply', 'search'), new PricingUsage(['requests' => 1]));
    }

    /** @return array{array<string, int|string>, ?string, int} */
    private function preflight(DriverRequest $request): array
    {
        $profile = $request->profile;
        $options = $profile->options;
        $credential = $profile->credential;
        if ($profile->id !== 'serply-search' || $profile->provider !== 'serply') {
            throw new DriverException('serply.unsupported_profile', 'The requested Serply Profile is not supported.', false);
        }
        if (! is_string($credential) || trim($credential) === '' || preg_match('/[\x00-\x20\x7f]/', $credential) === 1) {
            throw new DriverException('serply.authentication', 'The Serply provider is not configured.', false);
        }
        $gl = $options['gl'] ?? 'us';
        $num = $options['num'] ?? 10;
        $device = $options['device'] ?? null;
        if (array_diff(array_keys($options), ['gl', 'hl', 'num', 'device']) !== []
            || ! is_string($gl) || preg_match('/^[a-z]{2}$/D', $gl) !== 1
            || ! is_int($num) || $num < 1 || $num > 10
            || ($device !== null && ! in_array($device, ['desktop', 'mobile'], true))
            || (isset($options['hl']) && ! is_string($options['hl']))
            || trim($request->prompt) === '') {
            throw new DriverException('serply.invalid_request', 'The Serply query or options are invalid.', false);
        }

        $query = ['q' => $request->prompt, 'num' => $num, 'gl' => $gl];
        if (isset($options['hl'])) {
            $query['hl'] = $options['hl'];
        }

        return [$query, $device, $num];
    }

    /** @param array<string, int|string> $query */
    private function send(DriverRequest $request, array $query, ?string $device): Response
    {
        $remaining = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($remaining <= 0) {
            throw new DriverException('serply.deadline_exceeded', 'Serply did not respond before the research deadline.');
        }
        $headers = ['X-Api-Key' => (string) $request->profile->credential];
        if ($device !== null) {
            $headers['X-User-Agent'] = $device;
        }

        try {
            $response = Http::withHeaders($headers)
                ->acceptJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                ->get(self::URL, $query);
        } catch (ConnectionException) {
            $request->captureNoResponse(Operation::Inline);

            throw new DriverException('serply.connection', 'Serply could not be reached.');
        }
        $request->captureResponse($response, Operation::Inline);

        return $response;
    }

    /** @param list<mixed> $items */
    private function result(DriverRequest $request, array $items, int $num): ResearchResult
    {
        $parts = [];
        $citations = [];
        foreach ($items as $item) {
            if (count($citations) >= $num) {
                break;
            }
            if (! is_array($item)) {
                continue;
            }
            $url = EvidenceUrl::sanitize($item['link'] ?? null);
            $title = $this->str($item['title'] ?? null);
            if ($url === null || $title === null || isset($citations[$url])) {
                continue;
            }
            $snippet = $this->str($item['description'] ?? null);
            $rank = count($citations) + 1;
            $lines = ['### '.$rank.'. ['.$this->markdown(str_replace(["\r", "\n"], ' ', $title)).']('.str_replace(['\\', '(', ')'], ['%5C', '%28', '%29'], $url).')'];
            if ($snippet !== null) {
                $lines[] = $this->markdown($snippet);
            }
            $parts[] = implode("\n", $lines);
            $citations[$url] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(kind: SourceKind::WebPage, url: $url, title: $title),
                excerpt: $snippet,
            );
        }
        $now = CarbonImmutable::now();

        return ResearchResult::make(
            contentFormat: ContentFormat::Markdown,
            content: $parts === [] ? 'No results found.' : implode("\n\n", $parts),
            requestedProfile: $request->requestedProfile->id,
            provider: $request->profile->provider,
            profile: $request->profile->id,
            provenance: new ResultProvenance(
                resultKind: $request->profile->resultKind,
                retrievalMethods: $request->profile->retrievalMethods,
                corpora: $request->profile->corpora,
                observedAt: $now,
            ),
            citations: collect(array_values($citations)),
            completedAt: $now,
            providerMeta: (object) ['result_count' => count($citations)],
        );
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('serply.authentication', 'Serply rejected the configured API key.', false),
            $response->status() === 403 => new DriverException('serply.authorization', 'Serply denied this request.', false),
            $response->status() === 429 => new DriverException('serply.rate_limited', 'Serply rate limited this request.'),
            $response->serverError() => new DriverException('serply.unavailable', 'Serply is temporarily unavailable.'),
            default => new DriverException('serply.invalid_request', 'Serply rejected this request.', false),
        };
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_strcut(trim($value), 0, self::MAX_STRING_BYTES) : null;
    }

    private function markdown(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|', '~', '=', '^', '$'],
            ['\\\\', '\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\(', '\\)', '\\#', '\\+', '\\-', '\\.', '\\!', '\\|', '\\~', '\\=', '\\^', '\\$'],
            $escaped,
        );
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException('serply.invalid_response', 'Serply returned a malformed response.');
    }
}
