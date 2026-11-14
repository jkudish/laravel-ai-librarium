<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\SearchApi;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Jkudish\LaravelAiLibrarium\Collection\Enums\Operation;
use Jkudish\LaravelAiLibrarium\Execution\DriverRequest;

/**
 * Turns SearchAPI `link_token`s into destination URLs through SearchAPI's
 * Google Resolve API (POST engine=google_resolve), which uses no credits.
 *
 * Resolution is best effort and never fails the answer: a token that does
 * not resolve keeps the caller's fallback. Each exchange is captured as a
 * `retrieve` operation, so a consumer can tell these unbilled follow-up
 * reads from the billed `inline` searches. It runs before the capture is
 * redacted, so the tokens, which redaction removes, are still available.
 *
 * SearchAPI allows 120 resolve requests a minute and 5 at a time per
 * account. Both are enforced per credential through the default cache store,
 * which must provide locks; without a free slot within a short wait, or
 * without lock support, nothing is sent.
 */
final readonly class SearchApiLinkResolver
{
    private const string ENDPOINT = 'https://www.searchapi.io/api/v1/search';

    /** SearchAPI attempts at most this many distinct links per request. */
    public const int MAX_TOKENS = 25;

    private const int PER_MINUTE = 120;

    private const int CONCURRENT = 5;

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 10;

    /** Resends of not-ready rows, each only when it is due within MAX_RETRY_AFTER_SECONDS. */
    private const int MAX_RESENDS = 2;

    private const int MAX_RETRY_AFTER_SECONDS = 3;

    private const int SLOT_WAIT_MILLISECONDS = 3_000;

    private const int SLOT_POLL_MILLISECONDS = 250;

    /** Seconds of the research deadline a resolve request leaves untouched. */
    private const int DEADLINE_RESERVE_SECONDS = 2;

    /**
     * @param  list<string>  $tokens  distinct tokens; only the first MAX_TOKENS are sent
     * @param  callable(mixed): ?string  $accept  returns the URL to keep for a resolved link, or null to reject it
     * @return array<string, string> destination URL by token, for the tokens that resolved
     */
    public function resolve(DriverRequest $request, array $tokens, callable $accept): array
    {
        $pending = array_slice(array_values(array_unique($tokens)), 0, self::MAX_TOKENS);
        $resolved = [];

        for ($send = 0; $pending !== [] && $send <= self::MAX_RESENDS; $send++) {
            $rows = $this->send($request, $pending);
            if ($rows === null) {
                break;
            }

            $notReady = [];
            foreach ($pending as $index => $token) {
                $row = $rows['results'][$index];
                if (! array_key_exists('link_token', $row)) {
                    $url = $accept($row['link'] ?? null);
                    if ($url !== null) {
                        $resolved[$token] = $url;
                    }
                } elseif (! array_key_exists('error', $row)) {
                    $notReady[] = $token;
                }
            }

            $progress = count($notReady) < count($pending);
            $pending = $notReady;
            $wait = $rows['retry_after'];
            if ($pending === [] || (! $progress && $send > 0) || $wait === null || $wait > self::MAX_RETRY_AFTER_SECONDS
                || $this->remainingSeconds($request) - $wait <= self::DEADLINE_RESERVE_SECONDS) {
                break;
            }
            Sleep::for($wait)->seconds();
        }

        return $resolved;
    }

    /**
     * One resolve exchange under the account limits.
     *
     * @param  list<string>  $tokens
     * @return array{results: list<array<array-key, mixed>>, retry_after: int|null}|null null when nothing usable came back
     */
    private function send(DriverRequest $request, array $tokens): ?array
    {
        $credential = (string) $request->profile->credential;
        $key = 'librarium:searchapi-resolve:'.substr(hash('sha256', $credential), 0, 32);
        $slot = $this->slot($request, $key);
        if ($slot === null) {
            return null;
        }

        try {
            $remaining = $this->remainingSeconds($request) - self::DEADLINE_RESERVE_SECONDS;
            if ($remaining < 1 || ! RateLimiter::attempt($key.':minute', self::PER_MINUTE, static fn (): bool => true, 60)) {
                return null;
            }

            try {
                $response = Http::withToken($credential)
                    ->acceptJson()
                    ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                    ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining))
                    ->post(self::ENDPOINT, [
                        'engine' => 'google_resolve',
                        'results' => array_map(static fn (string $token): array => ['link_token' => $token], $tokens),
                    ]);
            } catch (ConnectionException) {
                $request->captureNoResponse(Operation::Retrieve);

                return null;
            }
        } finally {
            $slot->release();
        }

        $request->captureResponse($response, Operation::Retrieve);
        $json = $response->successful() ? $response->json() : null;
        $results = is_array($json) ? ($json['results'] ?? null) : null;
        if (! is_array($results) || ! array_is_list($results) || count($results) !== count($tokens)
            || array_any($results, static fn (mixed $row): bool => ! is_array($row))) {
            return null;
        }
        $retryAfter = $json['retry_after'] ?? null;

        /** @var list<array<array-key, mixed>> $results */
        return ['results' => $results, 'retry_after' => is_int($retryAfter) && $retryAfter >= 0 ? $retryAfter : null];
    }

    /** One of the account's concurrent request slots, waiting briefly for one to free. */
    private function slot(DriverRequest $request, string $key): ?Lock
    {
        $store = Cache::store()->getStore();
        if (! $store instanceof LockProvider) {
            return null;
        }

        $waited = 0;
        while (true) {
            for ($slot = 0; $slot < self::CONCURRENT; $slot++) {
                $lock = $store->lock("{$key}:slot:{$slot}", self::REQUEST_TIMEOUT_SECONDS + self::CONNECT_TIMEOUT_SECONDS + 5);
                if ($lock->get()) {
                    return $lock;
                }
            }
            if ($waited >= self::SLOT_WAIT_MILLISECONDS || $this->remainingSeconds($request) <= self::DEADLINE_RESERVE_SECONDS + 1) {
                return null;
            }
            Sleep::for(self::SLOT_POLL_MILLISECONDS)->milliseconds();
            $waited += self::SLOT_POLL_MILLISECONDS;
        }
    }

    private function remainingSeconds(DriverRequest $request): int
    {
        return (int) floor(CarbonImmutable::now()->diffInSeconds($request->deadline, false));
    }
}
