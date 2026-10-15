<?php

declare(strict_types=1);

namespace AhaSend\Laravel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

final class WelcomeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome!', tags: ['welcome'], metadata: ['order' => '42']);
    }

    public function headers(): Headers
    {
        return new Headers(text: ['AhaSend-Track-Opens' => 'true', 'X-Campaign' => 'spring']);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Hello from Laravel</p>');
    }
}
