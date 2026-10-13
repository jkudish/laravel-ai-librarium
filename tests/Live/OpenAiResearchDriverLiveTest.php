<?php

declare(strict_types=1);

use Jkudish\LaravelAiLibrarium\Facades\Librarium;

it('runs one live OpenAI background research request', function (): void {
    $credential = getenv('OPENAI_API_KEY');
    if (! is_string($credential) || trim($credential) === '') {
        $this->markTestSkipped('Set OPENAI_API_KEY to run the live OpenAI research canary.');
    }
    $credential = trim($credential);

    $configuredModel = getenv('OPENAI_RESEARCH_MODEL');
    $model = is_string($configuredModel) && trim($configuredModel) !== ''
        ? trim($configuredModel)
        : 'gpt-6.1-sol';
    config()->set('librarium.sources.openai.key', $credential);
    config()->set('librarium.timeout', 1800);

    $run = Librarium::research('In one short paragraph, identify the latest stable Laravel major version and cite the official Laravel documentation.')
        ->deep()
        ->only(['openai'])
        ->options(['openai' => ['model' => $model]])
        ->run();
    $result = $run->result('openai');

    expect($run->status())->toBe('done')
        ->and($run->toArray()['depth'])->toBe('deep')
        ->and($result['provenance']['provider'])->toBe('openai')
        ->and($result['provenance']['variant'])->toBe('openai-research')
        ->and($result['provenance']['model'])->toBe($model)
        ->and($result['answer'])->toBeString()->not->toBeEmpty()
        ->and($result['citations'])->not->toBeEmpty();
});
