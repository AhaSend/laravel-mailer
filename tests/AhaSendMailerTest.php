<?php

declare(strict_types=1);

namespace AhaSend\Laravel\Tests;

use AhaSend\Laravel\Tests\Fixtures\WelcomeMail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Bridge\AhaSend\Event\AhaSendDeliveryEvent;
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendApiTransport;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AhaSendMailerTest extends TestCase
{
    public function testRegistersTheAhaSendMailer(): void
    {
        $transport = Mail::mailer('ahasend')->getSymfonyTransport();

        $this->assertInstanceOf(AhaSendApiTransport::class, $transport);
        $this->assertSame('ahasend+api://api.ahasend.com', (string) $transport);
        $this->assertSame(['transport' => 'ahasend'], config('mail.mailers.ahasend'));
    }

    public function testSendsAMailableThroughApiV2(): void
    {
        Mail::to('user@example.com')->send(new WelcomeMail());

        $request = $this->lastRequest();
        $this->assertSame('https://api.ahasend.com/v2/accounts/acc-123/messages', $request['url']);
        $this->assertContains('Authorization: Bearer aha-sk-test', $request['headers']);
        $this->assertSame(['email' => 'from@example.com', 'name' => 'Example App'], $request['body']['from']);
        $this->assertSame([['email' => 'user@example.com']], $request['body']['recipients']);
        $this->assertSame('Welcome!', $request['body']['subject']);
        $this->assertSame('<p>Hello from Laravel</p>', $request['body']['html_content']);
        $this->assertSame(['welcome'], $request['body']['tags']);
        $this->assertSame(['open' => true], $request['body']['tracking']);
        $this->assertEquals(['X-Campaign' => 'spring', 'X-Metadata-order' => '42'], $request['body']['headers']);
    }

    public function testCcAndBccRecipientsReceiveItWithoutABccHeader(): void
    {
        Mail::to('user@example.com')->cc('boss@example.com')->bcc('audit@example.com')->send(new WelcomeMail());

        $request = $this->lastRequest();
        $this->assertSame('https://api.ahasend.com/v2/accounts/acc-123/messages', $request['url']);
        $this->assertSame([['email' => 'user@example.com'], ['email' => 'boss@example.com'], ['email' => 'audit@example.com']], $request['body']['recipients']);
        $this->assertSame('boss@example.com', $request['body']['headers']['Cc']);
        $this->assertArrayNotHasKey('Bcc', $request['body']['headers']);
    }

    public function testQueuedMailIsSentByTheJob(): void
    {
        Mail::to('later@example.com')->queue(new WelcomeMail());

        $this->assertSame([['email' => 'later@example.com']], $this->lastRequest()['body']['recipients']);
        $this->assertSame(['welcome'], $this->lastRequest()['body']['tags']);
    }

    public function testMailerSettingsOverrideConfigServices(): void
    {
        config(['mail.mailers.ahasend' => ['transport' => 'ahasend', 'key' => 'aha-sk-other', 'account_id' => 'acc-other', 'host' => 'api.example.test']]);

        Mail::to('user@example.com')->send(new WelcomeMail());

        $this->assertSame('https://api.example.test/v2/accounts/acc-other/messages', $this->lastRequest()['url']);
        $this->assertContains('Authorization: Bearer aha-sk-other', $this->lastRequest()['headers']);
    }

    public function testMissingCredentialsAreReportedClearly(): void
    {
        config(['services.ahasend' => ['key' => 'aha-sk-test', 'account_id' => null]]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The AhaSend mailer needs an API key and an account ID: set AHASEND_API_KEY and AHASEND_ACCOUNT_ID');

        Mail::mailer('ahasend');
    }

    public function testRejectedRecipientsAreLaravelEventsAndDoNotFailTheSend(): void
    {
        $rejected = [];
        Event::listen(AhaSendDeliveryEvent::class, static function (AhaSendDeliveryEvent $event) use (&$rejected): void {
            $rejected[] = $event->getMessage();
        });
        $this->responseData = [
            ['object' => 'message', 'id' => '<0@ahasend.test>', 'recipient' => ['email' => 'ok@example.com'], 'status' => 'queued', 'error' => null],
            ['object' => 'message', 'id' => null, 'recipient' => ['email' => 'x@blocked.example'], 'status' => 'error', 'error' => '553 recipient domain is blocked'],
        ];

        Mail::to(['ok@example.com', 'x@blocked.example'])->send(new WelcomeMail());

        $this->assertSame(['553 recipient domain is blocked'], $rejected);
    }

    public function testRejectingEveryRecipientIsAlsoAnEventAndNotAnException(): void
    {
        $rejected = [];
        Event::listen(AhaSendDeliveryEvent::class, static function (AhaSendDeliveryEvent $event) use (&$rejected): void {
            $rejected[] = $event->getMessage();
        });
        $this->responseData = [
            ['object' => 'message', 'id' => null, 'recipient' => ['email' => 'x@blocked.example'], 'status' => 'error', 'error' => '553 recipient domain is blocked'],
        ];

        Mail::to('x@blocked.example')->send(new WelcomeMail());

        $this->assertSame(['553 recipient domain is blocked'], $rejected);
    }

    public function testTheSentMessageCarriesAhaSendsMessageId(): void
    {
        $sent = Mail::to('user@example.com')->send(new WelcomeMail());

        $this->assertSame('<0@ahasend.test>', $sent->getMessageId());
    }

    public function testApiErrorsThrow(): void
    {
        $this->app->instance(HttpClientInterface::class, new MockHttpClient(new JsonMockResponse(['message' => 'invalid API key'], ['http_code' => 401])));
        Mail::purge('ahasend');

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('invalid API key');

        Mail::to('user@example.com')->send(new WelcomeMail());
    }

    public function testCredentialsCanComeFromTheConfigFile(): void
    {
        config([
            'services.ahasend' => [],
            'ahasend.api_key' => 'aha-sk-from-config',
            'ahasend.account_id' => 'acc-from-config',
        ]);
        Mail::purge('ahasend');

        Mail::to('user@example.com')->send(new WelcomeMail());

        $this->assertSame('https://api.ahasend.com/v2/accounts/acc-from-config/messages', $this->lastRequest()['url']);
        $this->assertContains('Authorization: Bearer aha-sk-from-config', $this->lastRequest()['headers']);
    }
}
