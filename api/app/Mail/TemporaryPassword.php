<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Owner decision (25 Nov 2026): the temporary password an admin sets is emailed
 * to the user, who must change it at the next sign-in. Production needs a real
 * mailer in .env (MAIL_MAILER); the development "log" mailer writes the message,
 * including the temporary password, to the local log.
 */
class TemporaryPassword extends Mailable
{
    use Queueable;

    public function __construct(public string $name, public string $username, public string $password, public bool $isNew) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->isNew ? 'Your Red Pharma POS account' : 'Your Red Pharma POS password was reset');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.temporary-password');
    }
}
