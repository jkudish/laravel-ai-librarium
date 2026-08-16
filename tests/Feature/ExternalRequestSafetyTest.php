<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Jkudish\LaravelAiLibrarium\Drivers\LaravelAi\GroundedResearchAgent;

it('prevents external HTTP requests in the ordinary test suite', function (): void {
    Http::get('https://example.com/should-never-run');
})->throws(StrayRequestException::class);

it('prevents unfaked AI prompts in the ordinary test suite', function (): void {
    (new GroundedResearchAgent)->prompt(
        'This prompt should never reach a provider.',
        provider: 'gemini',
        model: 'gemini-3.6-flash',
    );
})->throws(RuntimeException::class, 'without a fake agent response');
