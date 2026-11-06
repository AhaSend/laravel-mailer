<?php

declare(strict_types=1);

namespace AhaSend\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendSmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class SmtpMailerTest extends TestCase
{
    public function testTheMailerUsesAhaSendsSmtpTransportWithTheConfiguredCredentials(): void
    {
        config(['ahasend.smtp' => ['username' => 'smtp-user', 'password' => 'smtp-pass']]);

        $transport = Mail::mailer('ahasend-smtp')->getSymfonyTransport();

        $this->assertInstanceOf(AhaSendSmtpTransport::class, $transport);
        $this->assertSame('smtp://send.ahasend.com:587', (string) $transport);
        $this->assertSame('smtp-user', $transport->getUsername());
        $this->assertSame('smtp-pass', $transport->getPassword());
    }

    public function testStartTlsIsRequired(): void
    {
        config(['ahasend.smtp' => ['username' => 'smtp-user', 'password' => 'smtp-pass']]);

        $transport = Mail::mailer('ahasend-smtp')->getSymfonyTransport();

        $this->assertTrue((new \ReflectionProperty(EsmtpTransport::class, 'requireTls'))->getValue($transport));
    }

    public function testMailerSettingsOverrideTheConfiguration(): void
    {
        config([
            'ahasend.smtp' => ['username' => 'smtp-user', 'password' => 'smtp-pass'],
            'mail.mailers.ahasend-smtp' => ['transport' => 'ahasend-smtp', 'username' => 'other-user', 'password' => 'other-pass'],
        ]);

        $this->assertSame('other-user', Mail::mailer('ahasend-smtp')->getSymfonyTransport()->getUsername());
    }

    public function testMissingCredentialsAreReportedClearly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The AhaSend SMTP mailer needs SMTP credentials: set AHASEND_SMTP_USERNAME and AHASEND_SMTP_PASSWORD.');

        Mail::mailer('ahasend-smtp');
    }
}
