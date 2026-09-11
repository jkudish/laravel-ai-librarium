<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Jkudish\LaravelAiLibrarium\Drivers\TerminalRedaction;
use Jkudish\LaravelAiLibrarium\Exceptions\DriverException;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ContentFormat;
use Jkudish\LaravelAiLibrarium\Responses\Enums\ResultKind;
use Jkudish\LaravelAiLibrarium\Responses\ResearchResult;
use Jkudish\LaravelAiLibrarium\Responses\ResultProvenance;

it('rejects credentials and private handles anywhere in retained terminal values', function (string $private): void {
    $content = (object) [
        'safe' => [
            'nested' => "prefix {$private} suffix",
        ],
    ];

    expect(fn () => TerminalRedaction::guard(terminalRedactionResult($content), [$private]))
        ->toThrow(function (DriverException $exception) use ($private): void {
            expect($exception->errorCode)->toBe('librarium.unsafe_result')
                ->and($exception->fallbackAllowed)->toBeFalse()
                ->and($exception->getMessage())->not->toContain($private);
        });
})->with([
    'slash' => ['private/value'],
    'quote' => ['private"value'],
    'backslash' => ['private\\value'],
    'unicode' => ['private-秘密'],
]);

it('accepts nested retained terminal values that do not reflect private material', function (): void {
    $result = terminalRedactionResult((object) [
        'report' => ['text' => 'Public result', 'sources' => [['title' => 'Public source']]],
    ]);

    expect(TerminalRedaction::guard($result, ['private/value', 'testing-provider-key']))->toBe($result);
});

it('preserves a provider failure summary while making redaction failures non-fallback', function (): void {
    $failure = new DriverException(
        'test.invalid_response',
        'The provider returned a malformed response.',
    );

    expect(fn () => TerminalRedaction::guard(
        terminalRedactionResult((object) ['report' => 'Reflected testing-provider-key']),
        ['testing-provider-key'],
        $failure,
    ))->toThrow(function (DriverException $exception): void {
        expect($exception->errorCode)->toBe('test.invalid_response')
            ->and($exception->getMessage())->toBe('The provider returned a malformed response.')
            ->and($exception->fallbackAllowed)->toBeFalse();
    });
});

function terminalRedactionResult(stdClass $content): ResearchResult
{
    return new ResearchResult(
        id: 'result-1',
        contentFormat: ContentFormat::Json,
        content: $content,
        requestedProfile: 'test-profile',
        provider: 'test-provider',
        profile: 'test-profile',
        provenance: new ResultProvenance(
            resultKind: ResultKind::ResearchReport,
            retrievalMethods: collect(),
            corpora: collect(),
            observedAt: CarbonImmutable::now(),
        ),
        citations: collect(),
        completedAt: CarbonImmutable::now(),
    );
}
