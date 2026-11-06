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
 * Any verified AhaSend webhook, including event types this package does not know yet.
 */
final class WebhookReceived extends WebhookEvent
{
}
