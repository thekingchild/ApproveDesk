<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Registration confirmation code email (Phase UO.2).
 */
class RegistrationOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your ApproveDesk confirmation code'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.registration-otp', with: [
            'code' => $this->code,
            'minutes' => $this->minutes,
        ]);
    }
}
