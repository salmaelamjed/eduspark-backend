<?php

namespace App\Mail;

use App\Models\EmailVerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailCode extends Mailable
{
    use Queueable, SerializesModels;

    public $code;

    public function __construct(string  $verificationCode)
    {
        $this->code = $verificationCode;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Code de vérification de votre email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify-email-code',
              with: ['code' => $this->code]
        );
    }

    public function attachments(): array
    {
        return [];
    }
}