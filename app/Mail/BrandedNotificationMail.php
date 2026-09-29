<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BrandedNotificationMail extends Mailable
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
     * }  $branding
     * @param  list<array{label: string, value: string}>  $fields
     * @param  array{label: string, url: string}|null  $cta
     * @param  list<string>  $bullets
     */
    public function __construct(
        public readonly array $branding,
        public readonly string $subjectLine,
        public readonly string $eyebrow,
        public readonly string $heading,
        public readonly string $intro,
        public readonly array $fields = [],
        public readonly array $bullets = [],
        public readonly ?array $cta = null,
        public readonly ?string $closing = null,
        public readonly ?string $footerNote = null,
    ) {}

    public function envelope(): Envelope
    {
        $siteName = $this->branding['site_name'];

        return new Envelope(
            from: new Address($this->branding['from_email'], $siteName),
            replyTo: [
                new Address($this->branding['support_email'], $siteName),
            ],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.branded-notification',
            text: 'emails.branded-notification-text',
            with: [
                ...$this->branding,
                'eyebrow' => $this->eyebrow,
                'heading' => $this->heading,
                'intro' => $this->intro,
                'fields' => $this->fields,
                'bullets' => $this->bullets,
                'cta' => $this->cta,
                'closing' => $this->closing,
                'footer_note' => $this->footerNote,
            ],
        );
    }
}
