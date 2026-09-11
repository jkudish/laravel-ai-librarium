<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Brave;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
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

/** @internal */
final readonly class BraveAnswersResultMapper
{
    private const int MAX_STREAM_BYTES = 10 * 1024 * 1024;

    private const int MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    /** @var list<string> */
    private const array TAGS = ['citation', 'enum_item', 'usage'];

    public function map(Response $response, DriverRequest $request): ResearchResult
    {
        $stream = $this->stream($response, $request);
        $parsed = $this->parseTags($stream['content']);
        $content = trim($parsed['content']);

        if ($content === '') {
            throw new DriverException('brave.invalid_response', 'Brave Answers returned no answer.');
        }

        if ($parsed['citations'] === []) {
            throw new DriverException('brave.invalid_response', 'Brave Answers returned no valid citations.');
        }

        $now = CarbonImmutable::now();

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
            citations: collect($parsed['citations']),
            completedAt: $now,
            model: $stream['model'] ?? $request->profile->model,
            usage: $this->usage($parsed['usage'], $stream['usage'], $response),
        );
    }

    /** @return array{content: string, model: ?string, usage: ?array<array-key, mixed>} */
    private function stream(Response $response, DriverRequest $request): array
    {
        $body = $response->toPsrResponse()->getBody();
        $buffer = '';
        $content = '';
        $model = null;
        $usage = null;
        $bytes = 0;
        $raw = '';
        $complete = false;

        try {
            while (true) {
                $this->ensureBeforeDeadline($request);
                $chunk = $body->read(8192);
                $this->ensureBeforeDeadline($request);
                if ($chunk === '') {
                    if ($body->eof()) {
                        break;
                    }

                    throw new DriverException('brave.invalid_response', 'The Brave Answers stream ended unexpectedly.');
                }

                $bytes += strlen($chunk);
                if ($bytes > self::MAX_STREAM_BYTES) {
                    $body->close();

                    throw new DriverException('brave.response_too_large', 'The Brave Answers response exceeded the safe size limit.');
                }

                $raw .= $chunk;
                $buffer .= $chunk;
                while (preg_match('/\r?\n\r?\n/', $buffer, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $boundary = $match[0][1];
                    $this->consumeEvent(substr($buffer, 0, $boundary), $content, $model, $usage);
                    $buffer = substr($buffer, $boundary + strlen($match[0][0]));
                }
            }

            if (trim($buffer) !== '') {
                $this->consumeEvent($buffer, $content, $model, $usage);
            }

            $complete = true;

            return ['content' => $content, 'model' => $model, 'usage' => $usage];
        } finally {
            $request->captureResponse($response, Operation::Inline, $raw);

            if (! $complete) {
                $request->markCapturePartial();
            }
        }
    }

    /** @param array<array-key, mixed>|null $usage */
    private function consumeEvent(string $event, string &$content, ?string &$model, ?array &$usage): void
    {
        $lines = preg_split('/\r?\n/', $event);
        if (! is_array($lines)) {
            return;
        }

        $data = [];
        foreach ($lines as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $value = substr($line, 5);
            $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
        }

        $encoded = implode("\n", $data);
        if ($encoded === '' || $encoded === '[DONE]') {
            return;
        }

        $payload = json_decode($encoded, true);
        if (! is_array($payload) || array_is_list($payload)) {
            return;
        }

        $reportedModel = data_get($payload, 'model');
        if (is_string($reportedModel) && trim($reportedModel) !== '') {
            $model = trim($reportedModel);
        }

        $delta = data_get($payload, 'choices.0.delta.content');
        if (is_string($delta)) {
            $content .= $delta;
        }

        $reportedUsage = data_get($payload, 'usage');
        if (is_array($reportedUsage) && ! array_is_list($reportedUsage)) {
            $usage = $reportedUsage;
        }
    }

    /**
     * @return array{content: string, citations: list<Citation>, usage: ?array<array-key, mixed>}
     */
    private function parseTags(string $value): array
    {
        $content = '';
        $citations = [];
        $seen = [];
        $usage = null;
        $offset = 0;

        while (($tag = $this->nextTag($value, $offset)) !== null) {
            $content .= substr($value, $offset, $tag['offset'] - $offset);
            $payloadOffset = $tag['offset'] + strlen($tag['open']);
            $closingOffset = $this->closingTagOffset($value, $payloadOffset, $tag['close']);

            if ($closingOffset === null) {
                $offset = strlen($value);
                break;
            }

            $payload = substr($value, $payloadOffset, $closingOffset - $payloadOffset);
            $json = json_decode($payload, true);

            if (is_array($json) && ! array_is_list($json)) {
                if ($tag['name'] === 'citation') {
                    $citation = $this->citation($json);
                    $url = $citation?->source->url;
                    if ($citation !== null && $url !== null && ! isset($seen[$url])) {
                        $seen[$url] = true;
                        $citations[] = $citation;
                    }
                } elseif ($tag['name'] === 'usage') {
                    $usage = $json;
                }
            }

            $offset = $closingOffset + strlen($tag['close']);
        }

        $content .= substr($value, $offset);

        return ['content' => $content, 'citations' => $citations, 'usage' => $usage];
    }

    /** @return array{name: string, open: string, close: string, offset: int}|null */
    private function nextTag(string $value, int $offset): ?array
    {
        $next = null;

        foreach (self::TAGS as $name) {
            $open = "<{$name}>";
            $position = strpos($value, $open, $offset);
            if ($position !== false && ($next === null || $position < $next['offset'])) {
                $next = ['name' => $name, 'open' => $open, 'close' => "</{$name}>", 'offset' => $position];
            }
        }

        return $next;
    }

    private function closingTagOffset(string $value, int $offset, string $closingTag): ?int
    {
        $inString = false;
        $escaped = false;
        $length = strlen($value);

        for ($cursor = $offset; $cursor < $length; $cursor++) {
            $character = $value[$cursor];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;

                continue;
            }

            if (substr_compare($value, $closingTag, $cursor, strlen($closingTag)) === 0) {
                return $cursor;
            }
        }

        $fallback = strpos($value, $closingTag, $offset);

        return $fallback === false ? null : $fallback;
    }

    /** @param array<array-key, mixed> $value */
    private function citation(array $value): ?Citation
    {
        $url = $this->evidenceUrl(data_get($value, 'url'));
        if ($url === null) {
            return null;
        }

        return Citation::make(
            derivation: CitationDerivation::ProviderReported,
            source: new Source(
                kind: SourceKind::WebPage,
                url: $url,
                title: $this->firstString($value, ['title', 'source', 'name']),
            ),
            excerpt: $this->firstString($value, ['snippet', 'description']),
        );
    }

    /**
     * @param  array<array-key, mixed>|null  $inline
     * @param  array<array-key, mixed>|null  $stream
     */
    private function usage(?array $inline, ?array $stream, Response $response): ?Usage
    {
        $inline = $this->lowercaseKeys($inline);
        $promptTokens = $this->integer($inline['x-request-tokens-in'] ?? null)
            ?? $this->integer(data_get($stream, 'prompt_tokens'))
            ?? $this->headerInteger($response, 'X-Request-Tokens-In');
        $completionTokens = $this->integer($inline['x-request-tokens-out'] ?? null)
            ?? $this->integer(data_get($stream, 'completion_tokens'))
            ?? $this->headerInteger($response, 'X-Request-Tokens-Out');
        $cost = $this->decimal($inline['x-request-total-cost'] ?? null)
            ?? $this->headerDecimal($response, 'X-Request-Cost-Usd')
            ?? $this->headerDecimal($response, 'X-Request-Total-Cost-Usd')
            ?? $this->costBreakdown($response->header('X-Request-Cost-Breakdown'));

        if ($promptTokens === null && $completionTokens === null && $cost === null) {
            return null;
        }

        return new Usage(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            actualCost: $cost,
            currency: $cost === null ? null : 'USD',
        );
    }

    /** @param array<array-key, mixed>|null $value
     * @return array<string, mixed>
     */
    private function lowercaseKeys(?array $value): array
    {
        if ($value === null) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized[strtolower($key)] = $item;
            }
        }

        return $normalized;
    }

    private function headerInteger(Response $response, string $header): ?int
    {
        $value = $response->header($header);

        return preg_match('/^\d+$/D', $value) === 1 ? $this->integer((int) $value) : null;
    }

    private function headerDecimal(Response $response, string $header): ?string
    {
        return $this->decimal($response->header($header));
    }

    private function costBreakdown(string $value): ?string
    {
        if ($value === '' || strlen($value) > 65_536) {
            return null;
        }

        $json = json_decode($value, true);

        return is_array($json) ? $this->findCost($json) : null;
    }

    /** @param array<array-key, mixed> $value */
    private function findCost(array $value): ?string
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), ['cost_usd', 'costusd', 'total_usd', 'totalusd', 'usd'], true)) {
                $cost = $this->decimal($item);
                if ($cost !== null) {
                    return $cost;
                }
            }

            if (is_array($item)) {
                $cost = $this->findCost($item);
                if ($cost !== null) {
                    return $cost;
                }
            }
        }

        return null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= self::MAX_SAFE_INTEGER ? $value : null;
        }

        return is_float($value) && is_finite($value) && $value >= 0 && floor($value) === $value && $value <= self::MAX_SAFE_INTEGER
            ? (int) $value
            : null;
    }

    private function ensureBeforeDeadline(DriverRequest $request): void
    {
        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw new DriverException(
                'brave.deadline_exceeded',
                'Brave Answers did not respond before the research deadline.',
            );
        }
    }

    private function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value >= 0 ? (string) $value : null;
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0) {
                return null;
            }

            $value = (string) $value;
        }

        if (! is_string($value) || strlen($value) > 128 || ! is_numeric($value) || str_starts_with($value, '-')) {
            return null;
        }

        $decimal = $this->expandExponent($value);
        if (preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,18})?$/D', $decimal) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = rtrim($fraction, '0');

        return ($whole === '' ? '0' : $whole).($fraction === '' ? '' : ".{$fraction}");
    }

    private function expandExponent(string $value): string
    {
        if (preg_match('/^([0-9]+)(?:\.([0-9]+))?[eE]([+-]?\d+)$/D', $value, $matches) !== 1) {
            return $value;
        }

        $whole = $matches[1];
        $fraction = $matches[2];
        $exponent = filter_var($matches[3], FILTER_VALIDATE_INT);
        if (! is_int($exponent) || $exponent < -18 || $exponent > 100) {
            return $value;
        }

        $digits = $whole.$fraction;
        $decimalPosition = strlen($whole) + $exponent;

        return match (true) {
            $decimalPosition <= 0 => '0.'.str_repeat('0', -$decimalPosition).$digits,
            $decimalPosition >= strlen($digits) => $digits.str_repeat('0', $decimalPosition - strlen($digits)),
            default => substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition),
        };
    }

    /** @param array<array-key, mixed> $value
     * @param  list<string>  $keys
     */
    private function firstString(array $value, array $keys): ?string
    {
        foreach ($keys as $key) {
            $item = data_get($value, $key);
            if (is_string($item) && trim($item) !== '') {
                return trim($item);
            }
        }

        return null;
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
}
