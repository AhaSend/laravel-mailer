<?php

declare(strict_types=1);

namespace AhaSend\Laravel\Tests;

use AhaSend\Laravel\Events\ContactAddedToList;
use AhaSend\Laravel\Events\MessageBounced;
use AhaSend\Laravel\Events\MessageDelivered;
use AhaSend\Laravel\Events\WebhookReceived;
use AhaSend\Laravel\Http\Controllers\WebhookController;
use AhaSend\Laravel\Webhook\WebhookSignature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebhookTest extends TestCase
{
    private const SECRET = 'aha-whsec-the-whole-string-is-the-key';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('ahasend.webhook.secret', self::SECRET);
        $app['config']->set('ahasend.webhook.route_secret', 'route-secret');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param array<string, string>|null $signatureHeaders replaces the valid headers
     */
    private function deliver(string $body, string $secret = self::SECRET, ?array $signatureHeaders = null, ?string $uri = '/ahasend/webhook'): TestResponse
    {
        $timestamp = (string) Carbon::now()->getTimestamp();
        $headers = $signatureHeaders ?? [
            'webhook-id' => 'delivery-1',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => WebhookSignature::sign('delivery-1', $timestamp, $body, $secret),
        ];

        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.$name] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    private static function body(string $type = 'message.delivered'): string
    {
        return json_encode(['type' => $type, 'timestamp' => '2024-05-06T09:49:16.687031577Z', 'data' => ['recipient' => 'someone@example.com']]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function eventTypes(): iterable
    {
        foreach (WebhookController::EVENTS as $type => $class) {
            yield $type => [$type, $class];
        }
    }

    #[DataProvider('eventTypes')]
    public function testEachWebhookTypeDispatchesItsEvent(string $type, string $class): void
    {
        Event::fake([$class, WebhookReceived::class]);

        $this->deliver(self::body($type))->assertOk();

        Event::assertDispatched($class, static fn (object $event): bool => $type === $event->payload['type'] && 'delivery-1' === $event->headers['webhook-id']);
        Event::assertDispatched(WebhookReceived::class);
    }

    public function testEveryEventTypeInAhaSendsWebhookSpecIsMapped(): void
    {
        // The types documented in AhaSend's webhooks.yaml.
        $this->assertSame([
            'contact.added_to_list', 'contact.complained', 'contact.created', 'contact.deleted', 'contact.removed_from_list',
            'contact.subscribed', 'contact.unsubscribed', 'contact.updated', 'domain.dns_error', 'message.bounced',
            'message.clicked', 'message.complained', 'message.delivered', 'message.failed', 'message.opened',
            'message.reception', 'message.routing', 'message.suppressed', 'message.transient_error', 'message.unsubscribed',
            'suppression.created', 'suppression.deleted',
        ], collect(WebhookController::EVENTS)->keys()->sort()->values()->all());
    }

    public function testAnUnknownEventTypeIsAcknowledgedAndDispatchedAsWebhookReceived(): void
    {
        Event::fake([WebhookReceived::class, MessageDelivered::class]);

        $this->deliver(self::body('message.something_new'))->assertOk();

        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $event): bool => 'message.something_new' === $event->payload['type']);
        Event::assertNotDispatched(MessageDelivered::class);
    }

    public function testAcceptsASignatureProducedByAhaSend(): void
    {
        // Payload, headers and secret of the delivered.json fixture in Symfony's
        // AhaSend bridge: a signature this package did not produce.
        config(['ahasend.webhook.secret' => 'nxLe:L:fZLb7J_Wb3uFeWX/&z4Ed#9&DxPL%Ud&:jhpAW1gLaR%AEFwfKnwp60cC']);
        Carbon::setTestNow(Carbon::createFromTimestamp(1730057850));
        Event::fake([MessageDelivered::class]);

        $body = json_encode(json_decode((string) file_get_contents(__DIR__.'/Fixtures/delivered.json'), true), \JSON_UNESCAPED_SLASHES);

        $this->deliver($body, signatureHeaders: [
            'webhook-id' => 'mA5gq7fS2T3jAVOpJZVHX077DYrpZv3IOe0CULKb2aBIqgAxZoRpuWYeEgZQdK3m',
            'webhook-timestamp' => '1730057850',
            'webhook-signature' => 'v1,vqb6WYaPO7TO47N1/X9TGUmPcoHfAM9TpBgCM3TmaQI=',
        ])->assertOk();

        Event::assertDispatched(MessageDelivered::class);
    }

    public function testTheSecretIsUsedLiterallyNotBase64Decoded(): void
    {
        config(['ahasend.webhook.secret' => 'whsec_'.base64_encode('decoded-key')]);
        Event::fake([MessageDelivered::class]);

        $this->deliver(self::body(), 'decoded-key')->assertForbidden();
        $this->deliver(self::body(), 'whsec_'.base64_encode('decoded-key'))->assertOk();
    }

    public function testARouteSecretIsAcceptedToo(): void
    {
        Event::fake([MessageDelivered::class]);

        $this->deliver(self::body(), 'route-secret')->assertOk();
    }

    public function testAnyOfSeveralSignaturesCanMatch(): void
    {
        Event::fake([MessageDelivered::class]);
        $timestamp = (string) Carbon::now()->getTimestamp();

        $this->deliver(self::body(), signatureHeaders: [
            'webhook-id' => 'id',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => 'v1,'.base64_encode('old').' '.WebhookSignature::sign('id', $timestamp, self::body(), self::SECRET),
        ])->assertOk();
    }

    public function testAWrongSignatureIsRefusedAndDispatchesNothing(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->deliver(self::body(), 'another-secret')->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testATamperedBodyIsRefused(): void
    {
        Event::fake([WebhookReceived::class]);
        $timestamp = (string) Carbon::now()->getTimestamp();

        $this->deliver(self::body('message.bounced'), signatureHeaders: [
            'webhook-id' => 'id',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => WebhookSignature::sign('id', $timestamp, self::body('message.delivered'), self::SECRET),
        ])->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteHeaders(): iterable
    {
        yield 'no headers' => [[]];
        yield 'no signature' => [['webhook-id' => 'id', 'webhook-timestamp' => '1730057850']];
        yield 'no id' => [['webhook-timestamp' => '1730057850', 'webhook-signature' => 'v1,x']];
        yield 'no timestamp' => [['webhook-id' => 'id', 'webhook-signature' => 'v1,x']];
        yield 'timestamp not a number' => [['webhook-id' => 'id', 'webhook-timestamp' => 'soon', 'webhook-signature' => 'v1,x']];
    }

    #[DataProvider('incompleteHeaders')]
    public function testIncompleteSignatureHeadersAreRefused(array $headers): void
    {
        Event::fake([WebhookReceived::class]);

        $this->deliver(self::body(), signatureHeaders: $headers)->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function clockOffsets(): iterable
    {
        yield 'at the past edge' => [300, true];
        yield 'at the future edge' => [-300, true];
        yield 'too old' => [301, false];
        yield 'too far in the future' => [-301, false];
    }

    #[DataProvider('clockOffsets')]
    public function testTheTimestampMustBeWithinTheTolerance(int $offset, bool $accepted): void
    {
        Event::fake([MessageDelivered::class]);
        $timestamp = '1730057850';
        Carbon::setTestNow(Carbon::createFromTimestamp((int) $timestamp + $offset));

        $response = $this->deliver(self::body(), signatureHeaders: [
            'webhook-id' => 'id',
            'webhook-timestamp' => $timestamp,
            'webhook-signature' => WebhookSignature::sign('id', $timestamp, self::body(), self::SECRET),
        ]);

        $accepted ? $response->assertOk() : $response->assertForbidden();
    }

    public function testEveryRequestIsRefusedWhenNoSecretIsConfigured(): void
    {
        config(['ahasend.webhook.secret' => null, 'ahasend.webhook.route_secret' => null]);
        Event::fake([WebhookReceived::class]);

        $this->deliver(self::body())->assertForbidden();

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testASignedBodyThatIsNotAnEventIsABadRequest(): void
    {
        Event::fake([WebhookReceived::class]);

        $this->deliver('not json')->assertStatus(400);
        $this->deliver('{"no":"type"}')->assertStatus(400);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testTheEventCarriesTheDecodedPayload(): void
    {
        Event::fake([MessageBounced::class, ContactAddedToList::class]);

        $this->deliver(self::body('message.bounced'))->assertOk();

        Event::assertDispatched(MessageBounced::class, static fn (MessageBounced $event): bool => 'someone@example.com' === $event->payload['data']['recipient']);
    }

    public function testTheRouteHasAName(): void
    {
        $this->assertStringEndsWith('/ahasend/webhook', route('ahasend.webhook'));
    }
}
