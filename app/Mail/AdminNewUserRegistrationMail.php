<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminNewUserRegistrationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{
     *   site_name: string,
     *   logo_url: ?string,
     *   primary_color: string,
     *   secondary_color: string,
     *   support_email: string,
     *   from_email: string,
     *   username: string,
     *   user_email: string,
     * }  $data
     */
    public function __construct(
        public readonly array $data
    ) {}

    public function envelope(): Envelope
    {
        $siteName = $this->data['site_name'];

        return new Envelope(
            from: new Address($this->data['from_email'], $siteName),
            subject: "[{$siteName}] New User Registration",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.admin-new-user-registration',
            text: 'emails.admin-new-user-registration-text',
            with: $this->data,
        );
    }
}
