New download purchase - Order #{{ $payment_id }}

Hello,

A Downloads purchase has been made.

Downloads sold:
@foreach ($download_list as $item)
- {{ $item['label'] }}@if (! empty($item['detail'])) ({{ $item['detail'] }})@endif
@endforeach

Purchased by: {{ $purchaser }}@if (! empty($purchaser_email)) <{{ $purchaser_email }}>@endif
Amount: {{ $price }}
Payment Method: {{ $payment_method }}

Thank you
