<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Testing;

use Jkudish\LaravelAiLibrarium\Contracts\RunStore;
use Jkudish\LaravelAiLibrarium\Exceptions\RunStoreConflict;
use Jkudish\LaravelAiLibrarium\Spec;
use Jkudish\LaravelAiLibrarium\StoredRun;
use PHPUnit\Framework\Assert;

trait RunStoreContractTests
{
    abstract protected function store(): RunStore;

    /** Executes the normative shared cases against a fresh store. */
    public function assertRunStoreContract(?RunStore $store = null): void
    {
        $store ??= $this->store();
        /** @var array{steps: list<array{op: string, id?: string, with?: array<string, mixed>, expected_version?: ?int, limit?: int, before?: string, expect: array{result?: null, version?: int, error?: string, equals_doc?: string, run_status?: string, ids?: list<string>}}> } $contract */
        $contract = Spec::read('fixtures/run-store/contract.json');
        /** @var array{run: array<string, mixed>, state: array<string, mixed>, files: array<string, string>, version: int} $fixture */
        $fixture = Spec::read('fixtures/run-store/stored-run.json');
        Assert::assertNotEmpty($contract['steps']);
        foreach ($contract['steps'] as $step) {
            $expected = $step['expect'];
            if ($step['op'] === 'save') {
                $doc = $fixture;
                $doc['run'] = array_replace($doc['run'], $step['with'] ?? []);
                if (isset($expected['error'])) {
                    try {
                        $store->save(StoredRun::fromArray($doc), $step['expected_version'] ?? null);
                        Assert::fail('Expected a RunStoreConflict.');
                    } catch (RunStoreConflict) {
                        Assert::assertSame('conflict', $expected['error']);
                    }
                } else {
                    Assert::assertArrayHasKey('version', $expected);
                    Assert::assertSame($expected['version'] ?? Assert::fail('Missing expected version.'), $store->save(StoredRun::fromArray($doc), $step['expected_version'] ?? null));
                }
            } elseif ($step['op'] === 'get') {
                $doc = $store->find($step['id'] ?? '');
                if (array_key_exists('result', $expected)) {
                    Assert::assertNull($doc);
                } else {
                    Assert::assertNotNull($doc);
                    Assert::assertArrayHasKey('version', $expected);
                    Assert::assertSame($expected['version'] ?? Assert::fail('Missing expected version.'), $doc->version);
                    if (isset($expected['run_status'])) {
                        Assert::assertSame($expected['run_status'], $doc->run->status());
                    }
                    if (isset($expected['equals_doc'])) {
                        Assert::assertEquals(StoredRun::fromArray($fixture)->toArray(), $doc->toArray());
                    }
                }
            } elseif ($step['op'] === 'list') {
                Assert::assertArrayHasKey('ids', $expected);
                Assert::assertSame($expected['ids'] ?? Assert::fail('Missing expected ids.'), array_map(fn ($run): string => $run->id, $store->list($step['limit'] ?? 50, $step['before'] ?? null)));
            } elseif ($step['op'] === 'delete') {
                $store->delete($step['id'] ?? '');
            } else {
                Assert::fail('Unknown shared store operation.');
            }
        }
    }
}
