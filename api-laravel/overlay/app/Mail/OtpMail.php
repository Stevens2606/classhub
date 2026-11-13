<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class OtpMail extends Mailable
{
    public function __construct(public string $code, public int $ttlMinutes)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ClassHub verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.otp');
    }
}
