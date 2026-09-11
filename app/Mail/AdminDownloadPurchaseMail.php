<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminDownloadPurchaseMail extends Mailable
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
     *   payment_id: string,
     *   purchaser: string,
     *   purchaser_email: ?string,
     *   price: string,
     *   payment_method: string,
     *   download_list: list<array{label: string, detail: ?string}>,
     * }  $data
     */
    public function __construct(
        public readonly array $data
    ) {}

    public function envelope(): Envelope
    {
        $siteName = $this->data['site_name'];
        $paymentId = $this->data['payment_id'];

        return new Envelope(
            from: new Address($this->data['from_email'], $siteName),
            subject: "New download purchase - Order #{$paymentId}",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.admin-download-purchase',
            text: 'emails.admin-download-purchase-text',
            with: $this->data,
        );
    }
}
