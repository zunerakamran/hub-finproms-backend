<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{
     *   username: string,
     *   site_name: string,
     *   logo_url: ?string,
     *   primary_color: string,
     *   secondary_color: string,
     *   support_email: string,
     *   from_email: string,
     *   receipt_id: string,
     *   date: string,
     *   payment_method: string,
     *   price: string,
     *   order_list: list<array{label: string, detail: ?string, amount: string}>,
     *   invoice_url: string,
     *   login_url: string,
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
            replyTo: [
                new Address($this->data['support_email'], $siteName),
            ],
            subject: "Your {$siteName} Order is Confirmed!",
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.order-confirmation',
            text: 'emails.order-confirmation-text',
            with: $this->data,
        );
    }
}
