<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Jkudish\LaravelAiLibrarium\Tests\Support\ContractSnapshot;

beforeEach(function (): void {
    $this->workingSnapshotDirectory = sys_get_temp_dir().'/laravel-ai-librarium-contract-'.Str::uuid();

    expect(File::copyDirectory(
        __DIR__.'/../Support/Contracts/'.ContractSnapshot::CONTRACT_VERSION,
        $this->workingSnapshotDirectory,
    ))->toBeTrue();
});

afterEach(function (): void {
    File::deleteDirectory($this->workingSnapshotDirectory);
});

it('verifies the exact accepted upstream snapshot offline', function (): void {
    $manifest = ContractSnapshot::vendored()->verify();

    expect($manifest)
        ->snapshot_format_version->toBe(ContractSnapshot::SNAPSHOT_FORMAT_VERSION)
        ->checksum_algorithm->toBe('sha256')
        ->checksum_file->toBe('checksums.sha256')
        ->and($manifest['versions'])->toBe([
            'domain' => '1.0.0',
            'artifacts' => '1.0.0',
            'custom_provider' => '1.0.0',
            'interchange' => '1.0.0',
        ])
        ->and($manifest['files'])->toHaveCount(ContractSnapshot::MANIFEST_FILE_COUNT);
});

it('reports missing required files', function (): void {
    File::delete($this->workingSnapshotDirectory.'/schema/domain.schema.json');

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot is missing required file(s): schema/domain.schema.json.',
        );
});

it('reports files modified after the checksum snapshot was accepted', function (): void {
    $relativePath = 'fixtures/valid/interchange-request.json';
    File::append($this->workingSnapshotDirectory.'/'.$relativePath, "\n");

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(UnexpectedValueException::class, "Contract snapshot checksum mismatch for {$relativePath}");
});

it('reports unexpected extra files', function (): void {
    File::put($this->workingSnapshotDirectory.'/unexpected.json', '{}');

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot contains unexpected file(s): unexpected.json.',
        );
});

it('rejects a tampered checksum index before trusting its entries', function (): void {
    File::append($this->workingSnapshotDirectory.'/checksums.sha256', "\n");

    expect(fn (): array => (new ContractSnapshot($this->workingSnapshotDirectory))->verify())
        ->toThrow(
            UnexpectedValueException::class,
            'Contract snapshot checksum index does not match the pinned upstream baseline',
        );
});
