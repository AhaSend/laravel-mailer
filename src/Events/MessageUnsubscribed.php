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
 * message.unsubscribed: the recipient unsubscribed through the message.
 */
final class MessageUnsubscribed extends WebhookEvent
{
}
