Your {{ $site_name }} Order is Confirmed!

Hi {{ $username }},

Thank you for your order with {{ $site_name }}! We’ve received your payment and here are your purchase details.

Order Summary
-------------
Order Number: {{ $receipt_id }}
Date: {{ $date }}
Payment Method: {{ $payment_method }}
Total Paid: {{ $price }}

Order Details
-------------
@foreach ($order_list as $item)
- {{ $item['label'] }}@if (! empty($item['detail'])) ({{ $item['detail'] }})@endif — {{ $item['amount'] }}
@endforeach

Need Help or Have Questions?
- Track your credits usage: {{ $login_url }}
- View invoice: {{ $invoice_url }}
- Contact support: {{ $support_email }}

We appreciate your business and are here if you need anything!

Best regards,
{{ $site_name }}
