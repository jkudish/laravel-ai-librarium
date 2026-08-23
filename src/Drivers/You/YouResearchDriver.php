<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\You;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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

/** Laravel HTTP Driver for durable, standard-effort You.com Research tasks. */
final readonly class YouResearchDriver implements Driver
{
    private const string ENDPOINT = 'https://api.you.com/v1/research';

    private const float CONNECT_TIMEOUT_SECONDS = 3.0;

    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int POLL_INTERVAL_SECONDS = 1;

    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return [
            'research_effort' => ['required', 'string', 'in:standard'],
        ];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $credential = $this->preflight($request);
        $taskId = $this->submit($request, $credential);
        $request->progress('You.com is researching in the background.');

        while (true) {
            $this->waitBeforePolling($request);
            $task = $this->task($request, $credential, $taskId);
            $status = data_get($task, 'status');

            if ($status === 'completed') {
                $terminal = $this->task($request, $credential, $taskId);
                if (data_get($terminal, 'status') !== 'completed') {
                    throw $this->invalidResponse();
                }

                return $this->result($request, $terminal);
            }

            if (in_array($status, ['failed', 'cancelled'], true)) {
                throw new DriverException(
                    'you_research.failed',
                    "You.com Research ended with status {$status}.",
                    false,
                );
            }

            if (! in_array($status, ['queued', 'running'], true)) {
                throw $this->invalidResponse();
            }
        }
    }

    private function preflight(DriverRequest $request): string
    {
        if ($request->profile->options !== ['research_effort' => 'standard']) {
            throw new DriverException(
                'you_research.invalid_request',
                'The You.com Research Profile requires standard effort.',
                false,
            );
        }

        if (trim($request->prompt) === '' || mb_strlen($request->prompt) > 40_000) {
            throw new DriverException(
                'you_research.invalid_request',
                'The You.com Research input is invalid.',
                false,
            );
        }

        if (blank($request->profile->credential)) {
            throw new DriverException(
                'you_research.authentication',
                'The You.com Research provider is not configured.',
                false,
            );
        }

        $this->remainingSeconds($request);

        return (string) $request->profile->credential;
    }

    private function submit(DriverRequest $request, string $credential): string
    {
        $response = $this->send($request, $credential, 'post', self::ENDPOINT, [
            'input' => $request->prompt,
            'research_effort' => 'standard',
            'background' => true,
        ]);

        if (! in_array($response->status(), [200, 201, 202], true)) {
            throw $this->httpFailure($response);
        }

        $payload = $this->json($response);
        $taskId = data_get($payload, 'task_id');
        if (! is_string($taskId) || ! $this->isUuid($taskId)
            || data_get($payload, 'type') !== 'research'
            || data_get($payload, 'status') !== 'queued') {
            throw $this->invalidResponse();
        }

        return $taskId;
    }

    /** @return array<array-key, mixed> */
    private function task(DriverRequest $request, string $credential, string $taskId): array
    {
        $response = $this->send($request, $credential, 'get', self::ENDPOINT.'/'.rawurlencode($taskId));
        if ($response->failed()) {
            throw $this->httpFailure($response);
        }

        $payload = $this->json($response);
        if (data_get($payload, 'id') !== $taskId
            || data_get($payload, 'task_type') !== 'research'
            || ! in_array(data_get($payload, 'status'), ['queued', 'running', 'completed', 'failed', 'cancelled'], true)) {
            throw $this->invalidResponse();
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function send(DriverRequest $request, string $credential, string $method, string $url, array $payload = []): Response
    {
        $remaining = $this->remainingSeconds($request);

        try {
            $pending = Http::withHeaders(['X-API-Key' => $credential])
                ->acceptJson()
                ->connectTimeout(min(self::CONNECT_TIMEOUT_SECONDS, $remaining))
                ->timeout(min(self::REQUEST_TIMEOUT_SECONDS, $remaining));

            $response = $method === 'post'
                ? $pending->post($url, $payload)
                : $pending->get($url);
        } catch (ConnectionException) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new DriverException(
                'you_research.connection',
                'You.com Research could not be reached.',
                false,
            );
        }

        $this->remainingSeconds($request);

        return $response;
    }

    private function remainingSeconds(DriverRequest $request): float
    {
        $seconds = CarbonImmutable::now()->diffInMilliseconds($request->deadline, false) / 1000;
        if ($seconds < 0.001) {
            throw $this->deadlineExceeded();
        }

        return $seconds;
    }

    private function waitBeforePolling(DriverRequest $request): void
    {
        $remaining = $this->remainingSeconds($request);
        Sleep::for(min(self::POLL_INTERVAL_SECONDS, $remaining))->seconds();

        if (CarbonImmutable::now()->greaterThanOrEqualTo($request->deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    private function deadlineExceeded(): DriverException
    {
        return new DriverException(
            'you_research.deadline_exceeded',
            'You.com Research did not complete before the research deadline.',
            false,
        );
    }

    private function httpFailure(Response $response): DriverException
    {
        return match (true) {
            $response->status() === 401 => new DriverException('you_research.authentication', 'You.com Research rejected the configured credential.', false),
            $response->status() === 402 => new DriverException('you_research.insufficient_credit', 'You.com Research could not run because the account has insufficient API credit.', false),
            $response->status() === 403 => new DriverException('you_research.authorization', 'You.com Research denied this request.', false),
            $response->status() === 408 => new DriverException('you_research.timeout', 'You.com Research timed out while processing this request.', false),
            $response->status() === 429 => new DriverException('you_research.rate_limited', 'You.com Research rate limited this request.', false),
            $response->serverError() => new DriverException('you_research.unavailable', 'You.com Research is temporarily unavailable.', false),
            default => new DriverException('you_research.invalid_request', 'You.com Research rejected this request.', false),
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

    /** @param array<array-key, mixed> $json */
    private function result(DriverRequest $request, array $json): ResearchResult
    {
        $output = data_get($json, 'result.output');
        $warnings = data_get($json, 'result.warnings', []);
        if (! is_array($output) || array_is_list($output)
            || ! is_array($warnings) || ! array_is_list($warnings)
            || array_any($warnings, static fn (mixed $warning): bool => ! is_string($warning))) {
            throw $this->invalidResponse();
        }

        $content = data_get($output, 'content');
        $contentType = data_get($output, 'content_type');
        $sourceValues = data_get($output, 'sources');
        if (! is_string($content) || trim($content) === '' || $contentType !== 'text'
            || ! is_array($sourceValues) || ! array_is_list($sourceValues)) {
            throw $this->invalidResponse();
        }

        [$citations, $sources] = $this->sources($sourceValues);
        $completedAt = CarbonImmutable::now();

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
            citations: $citations,
            completedAt: $completedAt,
            providerMeta: (object) [
                'you-com:research' => [
                    'research_effort' => 'standard',
                    'execution' => 'background',
                    'resumability' => 'durable_provider_task',
                    'sources' => $sources,
                    'warnings' => $warnings,
                ],
            ],
        );
    }

    /**
     * @param  list<mixed>  $values
     * @return array{Collection<int, Citation>, list<array{url: string, title?: string, snippets: list<string>}>}
     */
    private function sources(array $values): array
    {
        $citations = [];
        $sources = [];

        foreach ($values as $index => $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw $this->invalidResponse();
            }

            $url = $this->evidenceUrl(data_get($value, 'url'));
            $title = data_get($value, 'title');
            $snippets = data_get($value, 'snippets', []);
            if ($url === null
                || ($title !== null && (! is_string($title) || trim($title) === ''))
                || ! is_array($snippets) || ! array_is_list($snippets)
                || array_any($snippets, static fn (mixed $snippet): bool => ! is_string($snippet))) {
                throw $this->invalidResponse();
            }

            /** @var list<string> $nonblankSnippets */
            $nonblankSnippets = array_values(array_filter($snippets, static fn (mixed $snippet): bool => is_string($snippet) && trim($snippet) !== ''));
            $citations[] = Citation::make(
                derivation: CitationDerivation::ProviderReported,
                source: new Source(kind: SourceKind::WebPage, url: $url, title: is_string($title) ? $title : null),
                excerpt: isset($nonblankSnippets[0]) ? mb_substr($nonblankSnippets[0], 0, 200) : null,
                locator: '[['.($index + 1).']]',
            );
            $sources[] = array_filter([
                'url' => $url,
                'title' => $title,
                'snippets' => $nonblankSnippets,
            ], static fn (mixed $item): bool => $item !== null);
        }

        return [collect($citations), $sources];
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

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function invalidResponse(): DriverException
    {
        return new DriverException(
            'you_research.invalid_response',
            'You.com Research returned an invalid research report.',
            false,
        );
    }
}
