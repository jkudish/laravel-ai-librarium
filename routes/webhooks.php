<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Jkudish\LaravelAiLibrarium\Drivers\OpenAi\OpenAiWebhookController;

Route::post('/librarium/webhooks/openai', OpenAiWebhookController::class)
    ->name('librarium.webhooks.openai');
