<?php

declare(strict_types=1);

/*
 * This file is part of the ahasend/laravel-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AhaSend\Laravel\Http\Middleware;

use AhaSend\Laravel\Exceptions\InvalidWebhookSignature;
use AhaSend\Laravel\Webhook\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Refuses a webhook request that is not signed with a configured secret. When
 * no secret is configured every request is refused, so forgetting the secret
 * cannot leave a public endpoint that dispatches events for anyone.
 */
final class VerifyWebhookSignature
{
    public function handle(Request $request, \Closure $next): Response
    {
        $secrets = array_values(array_filter([config('ahasend.webhook.secret'), config('ahasend.webhook.route_secret')]));
        if (!$secrets) {
            throw new AccessDeniedHttpException('The AhaSend webhook secret is not configured: set AHASEND_WEBHOOK_SECRET.');
        }

        try {
            WebhookSignature::verify(
                $request->getContent(),
                $request->header('webhook-id'),
                $request->header('webhook-timestamp'),
                $request->header('webhook-signature'),
                $secrets,
                (int) config('ahasend.webhook.tolerance', 300),
                Carbon::now()->getTimestamp(),
            );
        } catch (InvalidWebhookSignature $e) {
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        }

        return $next($request);
    }
}
