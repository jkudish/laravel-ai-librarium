<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\Firecrawl;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

/** Firecrawl v2 search; no browser SDK is required for this source. */
final readonly class FirecrawlSearchDriver implements Driver
{
    /** @return array<string, mixed> */
    public static function profileOptionRules(): array
    {
        return ['sources' => ['sometimes', 'array', 'list', 'min:1'], 'sources.*' => ['string', 'in:web,news', 'distinct'], 'limit' => ['sometimes', 'integer:strict', 'between:1,100']];
    }

    public function run(DriverRequest $request): ResearchResult
    {
        $remaining = CarbonImmutable::now()->diffInSeconds($request->deadline, false);
        if ($remaining <= 0 || blank($request->profile->credential)) {
            throw new DriverException('firecrawl.unavailable', 'Firecrawl search cannot start without a credential and remaining time.', false);
        }
        $options = $request->profile->options;
        $sources = $options['sources'] ?? ['web'];
        try {
            $response = Http::withToken((string) $request->profile->credential)->withoutRedirecting()->acceptJson()->asJson()
                ->connectTimeout(min(3, $remaining))->timeout(min(30, $remaining))
                ->post('https://api.firecrawl.dev/v2/search', ['query' => $request->prompt, 'limit' => $options['limit'] ?? 10, 'sources' => $sources]);
        } catch (ConnectionException) {
            $request->captureNoResponse();
            throw new DriverException('firecrawl.connection', 'Firecrawl search could not be reached.', false);
        }
        $request->captureResponse($response);
        $json = $response->json();
        if ($response->status() !== 200 || ! is_array($json) || ($json['success'] ?? null) === false || filled($json['error'] ?? null)) {
            throw new DriverException('firecrawl.search_failed', 'Firecrawl search returned an unsuccessful response.', false);
        }
        $citations = [];
        $seen = [];
        foreach (is_array($sources) ? $sources : [] as $kind) {
            $items = is_string($kind) ? data_get($json, 'data.'.$kind, []) : [];
            foreach (is_array($items) ? $items : [] as $item) {
                $url = data_get($item, 'url');
                $url = is_string($url) ? EvidenceUrl::sanitize(trim($url)) : null;
                if ($url === null || ! str_starts_with($url, 'https://')) {
                    continue;
                }
                $key = preg_replace('/#.*$/', '', $url);
                $key = rtrim((string) $key, '/');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $title = data_get($item, 'title');
                $snippet = data_get($item, $kind === 'news' ? 'snippet' : 'description');
                $citations[] = Citation::make(CitationDerivation::ProviderReported, new Source(SourceKind::WebPage, url: $url, title: is_string($title) ? trim($title) : null), is_string($snippet) ? mb_substr(trim($snippet), 0, 200) : null);
            }
        }
        $now = CarbonImmutable::now();
        $credits = $json['creditsUsed'] ?? null;

        return TerminalRedaction::guard(ResearchResult::make(
            ContentFormat::Markdown, '', $request->requestedProfile->id, $request->profile->provider, $request->profile->id,
            new ResultProvenance($request->profile->resultKind, $request->profile->retrievalMethods, $request->profile->corpora, $now),
            collect($citations), $now, providerMeta: (object) (is_int($credits) && $credits >= 0 ? ['credits' => $credits] : []),
        ), [(string) $request->profile->credential]);
    }
}
