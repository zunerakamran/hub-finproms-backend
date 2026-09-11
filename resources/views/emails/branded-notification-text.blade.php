{{ $heading }}

{{ $intro }}

@if (! empty($bullets))
@foreach ($bullets as $bullet)
- {{ $bullet }}
@endforeach

@endif
@foreach ($fields as $field)
{{ $field['label'] }}: {{ $field['value'] }}
@endforeach

@if (! empty($cta['url']))
{{ $cta['label'] ?? 'Open' }}: {{ $cta['url'] }}
@endif

@if ($closing)
{{ $closing }}
@endif

Best regards,
{{ $site_name }}

@if ($footer_note)
{{ $footer_note }}
@else
Need help? {{ $support_email }}
@endif
