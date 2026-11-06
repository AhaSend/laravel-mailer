<?php

declare(strict_types=1);

/*
 * This file is part of the ahasend/laravel-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AhaSend\Laravel\Events;

/**
 * A verified AhaSend webhook. `$payload` is the decoded body (type, timestamp,
 * data); `$headers['webhook-id']` is the delivery's unique ID, which you can
 * use to ignore a delivery you already handled.
 */
abstract class WebhookEvent
{
    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly array $payload,
        public readonly array $headers = [],
    ) {
    }
}
