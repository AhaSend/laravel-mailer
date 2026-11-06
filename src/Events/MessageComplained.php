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
 * message.complained: the recipient reported the message as spam.
 */
final class MessageComplained extends WebhookEvent
{
}
