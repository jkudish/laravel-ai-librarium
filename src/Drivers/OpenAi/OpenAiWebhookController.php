<?php

declare(strict_types=1);

namespace Jkudish\LaravelAiLibrarium\Drivers\OpenAi;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Jkudish\LaravelAiLibrarium\ResearchState;
use Jkudish\LaravelAiLibrarium\Webhooks\WebhookSignalStore;
use StandardWebhooks\Webhook;
use Throwable;

/** @internal */
final readonly class OpenAiWebhookController
{
    private const int MAX_BODY_BYTES = 1_048_576;

    private const int MAX_SIGNATURE_HEADER_BYTES = 8_192;

    public function __construct(private WebhookSignalStore $webhooks) {}

    public function __invoke(Request $request): Response
    {
        $secret = config('librarium.webhooks.openai.secret');
        if (! is_string($secret) || trim($secret) === '') {
            return new Response(status: 404);
        }

        $encodedSecret = str_starts_with($secret, 'whsec_')
            ? substr($secret, strlen('whsec_'))
            : '';
        $decodedSecret = base64_decode($encodedSecret, true);
        if ($encodedSecret === '' || $decodedSecret === false || strlen($decodedSecret) < 16) {
            return new Response(status: 404);
        }

        $payload = $request->getContent();
        if (strlen($payload) > self::MAX_BODY_BYTES) {
            return new Response(status: 413);
        }

        $deliveryId = $request->header('webhook-id');
        $timestamp = $request->header('webhook-timestamp');
        $signature = $request->header('webhook-signature');
        if (! is_string($deliveryId) || ! is_string($timestamp) || ! is_string($signature)) {
            return new Response(status: 400);
        }

        if (
            strlen($deliveryId) > self::MAX_SIGNATURE_HEADER_BYTES
            || strlen($timestamp) > self::MAX_SIGNATURE_HEADER_BYTES
            || strlen($signature) > self::MAX_SIGNATURE_HEADER_BYTES
        ) {
            return new Response(status: 400);
        }

        try {
            $event = (new Webhook($secret))->verify($payload, [
                'webhook-id' => $deliveryId,
                'webhook-timestamp' => $timestamp,
                'webhook-signature' => $signature,
            ]);
        } catch (Throwable) {
            return new Response(status: 400);
        }

        if (! is_array($event)) {
            return new Response(status: 422);
        }

        $state = match (data_get($event, 'type')) {
            'response.completed' => ResearchState::Completed,
            'response.failed' => ResearchState::Failed,
            default => null,
        };

        if ($state === null) {
            return new Response(status: 204);
        }

        $responseId = data_get($event, 'data.id');
        if (! is_string($responseId) || ! str_starts_with($responseId, 'resp_')) {
            return new Response(status: 422);
        }

        try {
            $this->webhooks->record('openai', $responseId, $deliveryId, $state);
        } catch (Throwable) {
            return new Response(status: 422);
        }

        return new Response(status: 204);
    }
}
