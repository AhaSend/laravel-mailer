<?php

declare(strict_types=1);

use AhaSend\Laravel\Http\Controllers\WebhookController;
use AhaSend\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('webhook', WebhookController::class)
    ->middleware(VerifyWebhookSignature::class)
    ->name('webhook');
