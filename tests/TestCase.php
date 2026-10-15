<?php

declare(strict_types=1);

namespace AhaSend\Laravel\Tests;

use AhaSend\Laravel\AhaSendServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class TestCase extends Orchestra
{
    /** @var list<array{url: string, body: array<string, mixed>, headers: list<string>}> */
    protected array $requests = [];

    /** @var list<array<string, mixed>>|null response entries; null echoes every recipient as queued */
    protected ?array $responseData = null;

    protected function getPackageProviders($app): array
    {
        return [AhaSendServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('services.ahasend', ['key' => 'aha-sk-test', 'account_id' => 'acc-123']);
        $app['config']->set('mail.default', 'ahasend');
        $app['config']->set('mail.from', ['address' => 'from@example.com', 'name' => 'Example App']);
        $app['config']->set('queue.default', 'sync');

        $app->instance(HttpClientInterface::class, new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $body = json_decode($options['body'], true);
            $this->requests[] = ['url' => $url, 'body' => $body, 'headers' => $options['headers']];
            $emails = array_column($body['recipients'] ?? [...($body['to'] ?? []), ...($body['cc'] ?? []), ...($body['bcc'] ?? [])], 'email');
            $data = $this->responseData ?? array_map(static fn (string $email, int $i): array => [
                'object' => 'message', 'id' => "<{$i}@ahasend.test>", 'recipient' => ['email' => $email, 'name' => ''],
                'status' => 'queued', 'error' => null,
            ], $emails, array_keys($emails));

            return new JsonMockResponse(['object' => 'list', 'data' => $data], ['http_code' => 202]);
        }));
    }

    /**
     * @return array{url: string, body: array<string, mixed>, headers: list<string>}
     */
    protected function lastRequest(): array
    {
        $this->assertNotEmpty($this->requests, 'no request was sent to AhaSend');

        return $this->requests[array_key_last($this->requests)];
    }
}
