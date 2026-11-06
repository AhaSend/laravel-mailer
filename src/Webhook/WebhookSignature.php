<?php

declare(strict_types=1);

/*
 * This file is part of the ahasend/laravel-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AhaSend\Laravel\Webhook;

use AhaSend\Laravel\Exceptions\InvalidWebhookSignature;

/**
 * Verifies AhaSend's webhook signatures.
 *
 * AhaSend signs "{webhook-id}.{webhook-timestamp}.{raw body}" with HMAC-SHA256
 * and sends "v1,{base64}" in the webhook-signature header. The key is the
 * literal bytes of the secret, including any prefix: unlike stock Standard
 * Webhooks libraries, it is not Base64-decoded.
 */
final class WebhookSignature
{
    /**
     * @param list<string> $secrets accepted secrets
     *
     * @throws InvalidWebhookSignature
     */
    public static function verify(string $body, ?string $id, ?string $timestamp, ?string $signature, array $secrets, int $tolerance, int $now): void
    {
        if (null === $id || '' === $id || null === $timestamp || '' === $timestamp || null === $signature || '' === $signature) {
            throw new InvalidWebhookSignature('The webhook-id, webhook-timestamp and webhook-signature headers are required.');
        }
        if (!ctype_digit($timestamp) || 0 === (int) $timestamp) {
            throw new InvalidWebhookSignature('The webhook timestamp is invalid.');
        }
        if (abs($now - (int) $timestamp) > $tolerance) {
            throw new InvalidWebhookSignature('The webhook timestamp is outside the allowed time window.');
        }

        $provided = array_filter(explode(' ', $signature));
        foreach ($secrets as $secret) {
            $expected = self::sign($id, $timestamp, $body, $secret);
            foreach ($provided as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return;
                }
            }
        }

        throw new InvalidWebhookSignature('The webhook signature is invalid.');
    }

    public static function sign(string $id, string $timestamp, string $body, #[\SensitiveParameter] string $secret): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $secret, true));
    }
}
