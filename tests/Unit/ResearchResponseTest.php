<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Jkudish\LaravelAiLibrarium\Responses\Citation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\CitationDerivation;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Corpus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\Personalization;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResponseStatus;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\Enums\RetrievalMethod;
use Jkudish\LaravelAiLibrarium\Responses\Enums\SourceKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResponse;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;
use Jkudish\LaravelAiLibrarium\Responses\Source;

/** @return array<string, mixed> */
function responseFixture(string $path): array
{
    $contents = File::get(__DIR__.'/../Support/Contracts/v1/fixtures/'.$path);

    /** @var array<string, mixed> $fixture */
    $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    return $fixture;
}

it('round trips every accepted terminal response fixture without a mapper', function (string $fixture): void {
    $value = responseFixture("valid/{$fixture}");

    $response = ResearchResponse::fromArray($value);

    expect(json_encode($response->toArray(), JSON_THROW_ON_ERROR))->toBe(json_encode($value, JSON_THROW_ON_ERROR))
        ->and($response->results)->each->toBeInstanceOf(ResearchResult::class);
})->with([
    'markdown result' => ['markdown-success.json'],
    'text result' => ['php-text-success.json'],
    'JSON object result' => ['json-object-success.json'],
    'JSON array result' => ['json-array-success.json'],
    'partial response usage' => ['partial-top-level-usage.json'],
    'failed response' => ['failed.json'],
    'provider reference source' => ['provider-reference-source.json'],
    'untrusted URL identifier' => ['untrusted-url-identifier.json'],
    'closed enum coverage' => ['enum-branch-coverage.json'],
    'empty retrieval facts' => ['empty-retrieval-and-corpora.json'],
    'anonymous surface' => ['surface-context-authentication-anonymous.json'],
    'authenticated surface' => ['surface-context-authentication-authenticated.json'],
    'managed surface' => ['surface-context-authentication-managed.json'],
    'unknown authentication surface' => ['surface-context-authentication-unknown.json'],
    'optional result values' => ['optional-result-fields.json'],
    'provider metadata namespaces' => ['provider-meta-namespaces.json'],
    'provider metadata benign keys' => ['provider-meta-benign-keys.json'],
]);

it('rejects every invalid terminal response fixture through Laravel validation', function (string $fixture): void {
    expect(fn (): ResearchResponse => ResearchResponse::fromArray(responseFixture("invalid/{$fixture}")))
        ->toThrow(ValidationException::class);
})->with([
    'terminal version' => ['terminal-version.json'],
    'terminal source registry' => ['terminal-source-registry.json'],
    'terminal extensions' => ['terminal-extensions.json'],
    'terminal execution' => ['terminal-execution-field.json'],
    'legacy result' => ['result-legacy-fields.json'],
    'plain text content format' => ['result-plain-text-format.json'],
    'semantic facts' => ['result-semantic-facts.json'],
    'profile graph' => ['result-profile-graph.json'],
    'target graph' => ['result-target-graph.json'],
    'collection graph' => ['result-collection-graph.json'],
    'wrong JSON content' => ['result-wrong-content.json'],
    'citation source' => ['citation-no-source.json'],
    'source locator' => ['source-no-locator.json'],
    'top level provider metadata' => ['provider-meta-top-level.json'],
    'non-object provider metadata' => ['provider-meta-non-object.json'],
    'secret provider metadata' => ['provider-meta-secret.json'],
    'raw provider metadata' => ['provider-meta-raw.json'],
    'fused API key metadata' => ['provider-meta-fused-api-key.json'],
    'fused access token metadata' => ['provider-meta-fused-access-token.json'],
    'fused raw response metadata' => ['provider-meta-fused-raw-response.json'],
    'wrapped API key metadata' => ['provider-meta-wrapped-api-key.json'],
    'wrapped binary payload metadata' => ['provider-meta-wrapped-binary-payload.json'],
    'wrapped credentials metadata' => ['provider-meta-wrapped-credentials.json'],
    'wrapped raw response metadata' => ['provider-meta-wrapped-raw-response.json'],
    'wrapped session token metadata' => ['provider-meta-wrapped-session-token.json'],
    'rich error' => ['error-rich.json'],
    'not namespaced error' => ['error-not-namespaced.json'],
    'rich usage' => ['usage-rich.json'],
    'negative usage' => ['usage-negative.json'],
    'malformed decimal usage' => ['usage-malformed-decimal.json'],
    'cost missing currency' => ['usage-cost-no-currency.json'],
    'provenance context' => ['provenance-context-unknown.json'],
    'surface missing collector' => ['provenance-surface-no-collector.json'],
    'surface snapshot wrong result' => ['provenance-surface-snapshot-wrong-result.json'],
    'closed enum' => ['invalid-enum.json'],
    'timestamp' => ['invalid-timestamp.json'],
    'identifier' => ['invalid-id.json'],
    'succeeded terminal shape' => ['succeeded-shape.json'],
    'partial terminal shape' => ['partial-shape.json'],
    'failed terminal shape' => ['failed-shape.json'],
]);

it('does not introduce an interchange mapper or terminal execution model', function (): void {
    $files = collect(File::allFiles(__DIR__.'/../../src/Responses'))
        ->map(static fn (SplFileInfo $file): string => $file->getFilenameWithoutExtension())
        ->sort()
        ->values()
        ->all();

    expect($files)->toBe([
        'Authentication',
        'Citation',
        'CitationDerivation',
        'ContentFormat',
        'Corpus',
        'Personalization',
        'ResearchError',
        'ResearchResponse',
        'ResearchResult',
        'ResponseStatus',
        'ResultKind',
        'ResultProvenance',
        'RetrievalMethod',
        'Source',
        'SourceKind',
        'Usage',
    ]);
});

it('rejects secret-bearing serializable metadata and non-JSON-safe content', function (): void {
    $metadataFixture = responseFixture('valid/provider-meta-benign-keys.json');
    $metadataFixture['results'][0]['provider_meta']['com.example:usage']['sdkResponse'] = new class implements JsonSerializable
    {
        /** @return array<string, mixed> */
        public function jsonSerialize(): array
        {
            return [];
        }
    };

    $tokenFixture = responseFixture('valid/provider-meta-benign-keys.json');
    $tokenFixture['results'][0]['provider_meta']['com.example:usage']['sessionToken'] = 'secret';

    $jsonFixture = responseFixture('valid/json-object-success.json');
    $jsonFixture['results'][0]['content']['cost'] = INF;

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($metadataFixture))
        ->toThrow(ValidationException::class)
        ->and(fn (): ResearchResponse => ResearchResponse::fromArray($tokenFixture))
        ->toThrow(ValidationException::class)
        ->and(fn (): ResearchResponse => ResearchResponse::fromArray($jsonFixture))
        ->toThrow(ValidationException::class);
});

it('preserves accepted fractional UTC timestamp precision at every terminal level', function (): void {
    $fixture = responseFixture('valid/markdown-success.json');
    $fixture['completed_at'] = '2026-08-09T12:00:02.1Z';
    $fixture['results'][0]['completed_at'] = '2026-08-09T12:00:01.12Z';
    $fixture['results'][0]['provenance']['observed_at'] = '2026-08-09T12:00:00.123Z';
    $fixture['results'][0]['citations'][0]['source']['published_at'] = '2026-08-09T11:00:00.1234Z';

    expect(ResearchResponse::fromArray($fixture)->toArray())->toBe($fixture);
});

it('preserves legacy provenance without inventing an observation mode or changing its result kind', function (): void {
    $fixture = responseFixture('valid/markdown-success.json');

    $serialized = ResearchResponse::fromArray($fixture)->toArray();

    expect($serialized['results'][0]['provenance'])
        ->not->toHaveKey('observation_mode')
        ->and($serialized['results'][0]['provenance']['result_kind'])->toBe('grounded_answer');
});

it('preserves a strict empty context object and rejects list-shaped context', function (): void {
    $fixture = responseFixture('valid/markdown-success.json');
    $fixture['results'][0]['provenance']['context'] = new stdClass;

    $context = ResearchResponse::fromArray($fixture)->toArray()['results'][0]['provenance']['context'];

    expect($context)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($context))->toBe([]);

    $fixture['results'][0]['provenance']['context'] = [];

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
});

it('accepts each bounded personalization value and rejects arbitrary values', function (Personalization $personalization): void {
    $fixture = responseFixture('valid/surface-context-authentication-unknown.json');
    $fixture['results'][0]['provenance']['context']['personalization'] = $personalization->value;

    expect(ResearchResponse::fromArray($fixture)->results->sole()->provenance->context['personalization'])
        ->toBe($personalization);

    $fixture['results'][0]['provenance']['context']['personalization'] = 'tailored';

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
})->with(Personalization::cases());

it('normalizes caller-created Carbon timestamps to UTC without exposing wire timestamp parameters', function (): void {
    $source = new Source(SourceKind::WebPage, url: 'https://example.com', publishedAt: CarbonImmutable::parse('2026-08-09T12:00:00.123400-07:00'));
    $citation = new Citation('citation-1', CitationDerivation::ProviderReported, $source);
    $provenance = new ResultProvenance(
        ResultKind::GroundedAnswer,
        collect([RetrievalMethod::ResearchAgent]),
        collect([Corpus::Web]),
        CarbonImmutable::parse('2026-08-09T12:00:01.120000-07:00'),
    );
    $result = new ResearchResult(
        'result-1',
        ContentFormat::Markdown,
        '# Answer',
        'default',
        'provider',
        'default',
        $provenance,
        collect([$citation]),
        CarbonImmutable::parse('2026-08-09T12:00:02.100000-07:00'),
    );
    $response = new ResearchResponse(
        'generator',
        '1.0.0',
        'request-1',
        ResponseStatus::Succeeded,
        CarbonImmutable::parse('2026-08-09T12:00:03-07:00'),
        collect([$result]),
        collect(),
    );

    $serialized = $response->toArray();

    expect($serialized['completed_at'])->toBe('2026-08-09T19:00:03Z')
        ->and($serialized['results'][0]['completed_at'])->toBe('2026-08-09T19:00:02.1Z')
        ->and($serialized['results'][0]['provenance']['observed_at'])->toBe('2026-08-09T19:00:01.12Z')
        ->and($serialized['results'][0]['citations'][0]['source']['published_at'])->toBe('2026-08-09T19:00:00.1234Z');

    foreach ([Source::class, ResultProvenance::class, ResearchResult::class, ResearchResponse::class] as $class) {
        $parameters = collect((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [])
            ->map(static fn (ReflectionParameter $parameter): string => $parameter->getName());

        expect($parameters)->not->toContain('publishedAtWire', 'observedAtWire', 'completedAtWire');
    }
});

it('preserves JSON object and array identity through hydration and serialization', function (): void {
    $objectFixture = responseFixture('valid/json-object-success.json');
    $objectFixture['results'][0]['content'] = (object) ['0' => 'zero'];
    $objectFixture['results'][0]['provider_meta'] = (object) [
        'numeric' => (object) ['0' => 'zero'],
        'empty' => new stdClass,
        'list' => [],
    ];

    $serialized = ResearchResponse::fromArray($objectFixture)->toArray();

    expect($serialized['results'][0]['content'])->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($serialized['results'][0]['content']))->toBe(['0' => 'zero'])
        ->and($serialized['results'][0]['provider_meta'])->toBeInstanceOf(stdClass::class)
        ->and($serialized['results'][0]['provider_meta']->numeric)->toBeInstanceOf(stdClass::class)
        ->and($serialized['results'][0]['provider_meta']->empty)->toBeInstanceOf(stdClass::class)
        ->and($serialized['results'][0]['provider_meta']->list)->toBeArray()->toBe([]);
});

it('rejects an empty provider metadata property name', function (): void {
    $fixture = responseFixture('valid/json-object-success.json');
    $fixture['results'][0]['provider_meta'] = (object) ['' => 'value'];

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
});

it('rejects every provider metadata safety-policy branch', function (string $key): void {
    $fixture = responseFixture('valid/json-object-success.json');
    $fixture['results'][0]['provider_meta'] = (object) [
        'safe' => (object) [$key => 'value'],
    ];

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
})->with([
    'binary payload' => ['binary_payload'],
    'raw container' => ['raw'],
    'provider payload' => ['provider_payload'],
    'auth container' => ['auth'],
    'cookie container' => ['cookie'],
    'signed URL' => ['signed_url'],
    'normalized suffix' => ['vendor_auth'],
    'numeric suffix stripped' => ['api_key_2'],
    'namespace-local candidate' => ['com.example:signedURL'],
    'sensitive token suffix' => ['custom_token'],
    'fused sensitive token suffix' => ['customtoken'],
    'API key with trailing qualifier' => ['api_key_value'],
    'credentials with trailing qualifier' => ['credentials_blob'],
    'raw response with trailing qualifier' => ['raw_response_data'],
    'request headers with trailing qualifier' => ['request_headers_map'],
    'private key with trailing qualifier' => ['private_key_pem'],
    'authorization with trailing qualifier' => ['authorization_value'],
    'signed URL with trailing qualifier' => ['signed_url_value'],
    'session token with trailing qualifier' => ['session_token_value'],
    'binary payload with trailing qualifier' => ['binary_payload_data'],
    'fused API key with trailing qualifier' => ['apikeyvalue'],
    'fused raw response with trailing qualifier' => ['rawresponsedata'],
    'prefixed fused API key with trailing qualifier' => ['openaiapikeyvalue'],
    'prefixed fused raw response with trailing qualifier' => ['providerrawresponsedata'],
    'prefixed fused authorization with trailing qualifier' => ['vendorauthorizationvalue'],
    'prefixed fused session token with trailing qualifier' => ['vendorsessiontokenvalue'],
    'benign token with unsafe wrapper' => ['prompttokenvalue'],
    'multi-word token wrapper' => ['tokenResponseData'],
    'cookie jar' => ['cookieJar'],
    'namespaced wrapped API key' => ['com.example:openaiApiKeyValue'],
]);

it('rejects raw provider metadata containers defined by the shared contract', function (string $key, mixed $value): void {
    $fixture = responseFixture('valid/json-object-success.json');
    $fixture['results'][0]['provider_meta'] = (object) [$key => $value];

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
})->with([
    'camel case raw data' => ['rawData', ['safe' => true]],
    'provider response' => ['providerResponse', ['safe' => true]],
    'provider body' => ['providerBody', ['safe' => true]],
    'complete response container' => ['response', ['headers' => [], 'status' => 200]],
    'complete payload container' => ['payload', ['headers' => [], 'status_code' => 200]],
    'complete body container' => ['body', (object) ['headers' => [], 'status' => 200]],
]);

it('accepts benign token accounting names', function (string $key): void {
    $fixture = responseFixture('valid/json-object-success.json');
    $fixture['results'][0]['provider_meta'] = (object) [$key => 10];

    expect(ResearchResponse::fromArray($fixture)->toArray()['results'][0]['provider_meta'])
        ->toBeInstanceOf(stdClass::class);
})->with([
    'snake case' => ['completion_token'],
    'qualified snake case' => ['vendor_completion_token'],
    'camel case' => ['completionToken'],
    'fused' => ['completiontoken'],
    'session token count' => ['session_tokens_count'],
    'secret count' => ['secret_count'],
    'password policy' => ['password_policy'],
    'credentials guide URL' => ['credentials_guide_url'],
    'fused provider payload description' => ['providerpayloadvalue'],
    'prefixed fused provider payload' => ['vendorproviderpayload'],
    'numeric raw description' => ['raw2'],
]);

it('rejects Unicode edge whitespace in shared opaque identifiers', function (string $fixtureName, string $path): void {
    $fixture = responseFixture("valid/{$fixtureName}");
    data_set($fixture, $path, "\u{00A0}".data_get($fixture, $path));

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
})->with([
    'request ID' => ['markdown-success.json', 'request_id'],
    'result ID' => ['markdown-success.json', 'results.0.id'],
    'citation ID' => ['markdown-success.json', 'results.0.citations.0.id'],
    'provider reference' => ['provider-reference-source.json', 'results.0.citations.0.source.provider_reference'],
]);

it('rejects explicit null for every optional property while allowing omission', function (string $fixtureName, string $path): void {
    $fixture = responseFixture("valid/{$fixtureName}");
    data_set($fixture, $path, null);

    expect(fn (): ResearchResponse => ResearchResponse::fromArray($fixture))
        ->toThrow(ValidationException::class);
})->with([
    ['partial-top-level-usage.json', 'usage'],
    ['optional-result-fields.json', 'results.0.model'],
    ['optional-result-fields.json', 'results.0.fallback_reason'],
    ['optional-result-fields.json', 'results.0.usage'],
    ['provider-meta-benign-keys.json', 'results.0.provider_meta'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.collector'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.surface'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.context'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.context.locale'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.context.country'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.context.device'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.context.authentication'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.context.personalization'],
    ['surface-context-authentication-authenticated.json', 'results.0.provenance.observation_mode'],
    ['markdown-success.json', 'results.0.citations.0.excerpt'],
    ['markdown-success.json', 'results.0.citations.0.locator'],
    ['markdown-success.json', 'results.0.citations.0.source.url'],
    ['provider-reference-source.json', 'results.0.citations.0.source.provider_reference'],
    ['markdown-success.json', 'results.0.citations.0.source.title'],
    ['markdown-success.json', 'results.0.citations.0.source.publisher'],
    ['markdown-success.json', 'results.0.citations.0.source.published_at'],
    ['failed.json', 'errors.0.profile'],
    ['optional-result-fields.json', 'results.0.usage.prompt_tokens'],
    ['optional-result-fields.json', 'results.0.usage.completion_tokens'],
    ['optional-result-fields.json', 'results.0.usage.cache_write_input_tokens'],
    ['optional-result-fields.json', 'results.0.usage.cache_read_input_tokens'],
    ['optional-result-fields.json', 'results.0.usage.reasoning_tokens'],
    ['optional-result-fields.json', 'results.0.usage.actual_cost'],
    ['optional-result-fields.json', 'results.0.usage.estimated_cost'],
    ['optional-result-fields.json', 'results.0.usage.currency'],
]);
