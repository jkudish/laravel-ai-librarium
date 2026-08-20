<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiWebhookController;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;

beforeEach(function (): void {
    config()->set('librarium.webhooks.openai.secret', 'whsec_'.base64_encode('test-webhook-secret'));
});

it('registers the OpenAI webhook route', function (): void {
    $route = app('router')->getRoutes()->getByName('librarium.webhooks.openai');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('librarium/webhooks/openai')
        ->and($route?->methods())->toContain('POST');
});

it('verifies and records completed and failed webhook hints', function (string $type, ResearchState $state): void {
    $store = app(WebhookSignalStore::class);
    $responseId = 'resp_'.str_replace('.', '_', $type);
    $store->bind('openai', 'request-1', 'openai-background', $responseId);

    $response = app(OpenAiWebhookController::class)(signedOpenAiWebhook([
        'type' => $type,
        'data' => ['id' => $responseId],
    ], 'delivery-1'));

    expect($response->getStatusCode())->toBe(204)
        ->and($store->get('request-1', 'openai-background')?->state)->toBe($state);
})->with([
    ['response.completed', ResearchState::Completed],
    ['response.failed', ResearchState::Failed],
]);

it('rejects missing or invalid webhook signatures', function (): void {
    $unsigned = Request::create(
        '/librarium/webhooks/openai',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: '{"type":"response.completed","data":{"id":"resp_test"}}',
    );
    $invalid = signedOpenAiWebhook([
        'type' => 'response.completed',
        'data' => ['id' => 'resp_test'],
    ], 'delivery-invalid');
    $invalid->headers->set('webhook-signature', 'v1,invalid');

    expect(app(OpenAiWebhookController::class)($unsigned)->getStatusCode())->toBe(400)
        ->and(app(OpenAiWebhookController::class)($invalid)->getStatusCode())->toBe(400);
});

it('rejects signed webhook timestamps outside the verification window', function (int $timestamp): void {
    $request = signedOpenAiWebhookBody(
        '{"type":"response.completed","data":{"id":"resp_test"}}',
        'delivery-expired',
        $timestamp,
    );

    expect(app(OpenAiWebhookController::class)($request)->getStatusCode())->toBe(400);
})->with([
    'stale' => fn (): int => time() - 301,
    'future' => fn (): int => time() + 301,
]);

it('ignores signed webhook events that are not terminal research hints', function (): void {
    $response = app(OpenAiWebhookController::class)(signedOpenAiWebhook([
        'type' => 'response.in_progress',
        'data' => ['id' => 'resp_unbound'],
    ], 'delivery-ignored'));

    expect($response->getStatusCode())->toBe(204);
});

it('handles duplicate terminal deliveries idempotently', function (): void {
    $store = app(WebhookSignalStore::class);
    $store->bind('openai', 'request-duplicate', 'openai-background', 'resp_duplicate');
    $request = signedOpenAiWebhook([
        'type' => 'response.completed',
        'data' => ['id' => 'resp_duplicate'],
    ], 'delivery-duplicate');

    expect(app(OpenAiWebhookController::class)($request)->getStatusCode())->toBe(204)
        ->and(app(OpenAiWebhookController::class)($request)->getStatusCode())->toBe(204)
        ->and($store->get('request-duplicate', 'openai-background')?->state)->toBe(ResearchState::Completed);
});

it('rejects malformed or unbound response references without exposing details', function (array $event): void {
    $response = app(OpenAiWebhookController::class)(signedOpenAiWebhook($event, 'delivery-unbound'));

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getContent())->toBeEmpty();
})->with([
    [['type' => 'response.completed', 'data' => ['id' => 'invalid']]],
    [['type' => 'response.completed', 'data' => ['id' => 'resp_unbound']]],
]);

it('uses the raw request body when verifying signatures', function (): void {
    $store = app(WebhookSignalStore::class);
    $store->bind('openai', 'request-raw', 'openai-background', 'resp_raw');
    $body = "{\n  \"type\": \"response.completed\",\n  \"data\": {\"id\": \"resp_raw\"}\n}";

    $response = app(OpenAiWebhookController::class)(signedOpenAiWebhookBody($body, 'delivery-raw'));

    expect($response->getStatusCode())->toBe(204)
        ->and($store->get('request-raw', 'openai-background')?->state)->toBe(ResearchState::Completed);
});

it('disables the webhook endpoint when no secret is configured', function (): void {
    config()->set('librarium.webhooks.openai.secret', null);

    $response = app(OpenAiWebhookController::class)(Request::create(
        '/librarium/webhooks/openai',
        'POST',
        content: '{}',
    ));

    expect($response->getStatusCode())->toBe(404);
});

it('disables the webhook endpoint when its secret is malformed', function (string $secret): void {
    config()->set('librarium.webhooks.openai.secret', $secret);

    $response = app(OpenAiWebhookController::class)(Request::create(
        '/librarium/webhooks/openai',
        'POST',
        content: '{}',
    ));

    expect($response->getStatusCode())->toBe(404);
})->with([
    'missing prefix' => 'not-prefixed',
    'invalid base64' => 'whsec_!!!!',
    'empty key' => 'whsec_',
    'weak key' => 'whsec_'.base64_encode('short'),
]);

it('rejects oversized webhook bodies before signature verification', function (): void {
    $request = Request::create(
        '/librarium/webhooks/openai',
        'POST',
        content: str_repeat('x', 1_048_577),
    );

    expect(app(OpenAiWebhookController::class)($request)->getStatusCode())->toBe(413);
});

it('rejects oversized webhook signature headers', function (): void {
    $request = Request::create(
        '/librarium/webhooks/openai',
        'POST',
        server: [
            'HTTP_WEBHOOK_ID' => 'delivery-oversized',
            'HTTP_WEBHOOK_TIMESTAMP' => (string) time(),
            'HTTP_WEBHOOK_SIGNATURE' => str_repeat('x', 8_193),
        ],
        content: '{}',
    );

    expect(app(OpenAiWebhookController::class)($request)->getStatusCode())->toBe(400);
});

/** @param array<string, mixed> $event */
function signedOpenAiWebhook(array $event, string $deliveryId): Request
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);

    return signedOpenAiWebhookBody($body, $deliveryId);
}

function signedOpenAiWebhookBody(string $body, string $deliveryId, ?int $signedAt = null): Request
{
    $timestamp = (string) ($signedAt ?? time());
    $signature = base64_encode(hash_hmac(
        'sha256',
        $deliveryId.'.'.$timestamp.'.'.$body,
        'test-webhook-secret',
        true,
    ));

    return Request::create(
        '/librarium/webhooks/openai',
        'POST',
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_WEBHOOK_ID' => $deliveryId,
            'HTTP_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => 'v1,'.$signature,
        ],
        content: $body,
    );
}
