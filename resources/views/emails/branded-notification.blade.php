<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
    <style>
        html, body { margin: 0 !important; padding: 0 !important; width: 100% !important; }
        table, td { mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; }
        img { border: 0; display: block; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .summary-label, .summary-value { display: block !important; width: 100% !important; text-align: left !important; }
            .summary-value { margin: 4px 0 12px 0 !important; }
            .mobile-full-btn a { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8;">
    <center style="width:100%; background-color:#f4f6f8;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:600px; margin:0 auto;" class="email-container">
            <tr>
                <td style="padding:24px 16px 12px 16px; text-align:center;">
                    @if ($logo_url)
                        <img src="{{ $logo_url }}" alt="{{ $site_name }}" width="160" style="max-width:160px; height:auto; margin:0 auto;">
                    @else
                        <div style="font-family:Arial, Helvetica, sans-serif; font-size:22px; font-weight:700; color:{{ $primary_color }};">{{ $site_name }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:600px; margin:0 auto;" class="email-container">
            <tr>
                <td style="background-color:{{ $primary_color }}; border-radius:12px 12px 0 0; padding:28px 28px 24px 28px;" class="mobile-padding">
                    <div style="font-family:Arial, Helvetica, sans-serif; color:#ffffff;">
                        <div style="font-size:13px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.9; margin-bottom:8px;">{{ $eyebrow }}</div>
                        <div style="font-size:22px; line-height:1.3; font-weight:700;">{{ $heading }}</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td style="background-color:#ffffff; padding:28px 28px 12px 28px;" class="mobile-padding">
                    <p style="margin:0 0 16px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.65; color:#4b5563;">{!! nl2br(e($intro)) !!}</p>

                    @if (! empty($bullets))
                        <ul style="margin:0 0 16px 0; padding-left:20px; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6; color:#374151;">
                            @foreach ($bullets as $bullet)
                                <li style="margin-bottom:6px;">{{ $bullet }}</li>
                            @endforeach
                        </ul>
                    @endif

                    @if (! empty($fields))
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:16px;">
                            <tr>
                                <td style="padding:16px 18px;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#374151;">
                                        @foreach ($fields as $field)
                                            <tr>
                                                <td class="summary-label" style="padding:8px 0; {{ ! $loop->first ? 'border-top:1px solid #e5e7eb;' : '' }} color:#6b7280; width:38%; vertical-align:top;">{{ $field['label'] }}</td>
                                                <td class="summary-value" style="padding:8px 0; {{ ! $loop->first ? 'border-top:1px solid #e5e7eb;' : '' }} font-weight:600; color:#111827; text-align:right; vertical-align:top;">{!! nl2br(e($field['value'])) !!}</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </td>
                            </tr>
                        </table>
                    @endif

                    @if (! empty($cta['url']))
                        <div class="mobile-full-btn" style="margin:8px 0 16px 0;">
                            <a href="{{ $cta['url'] }}" style="display:inline-block; background-color:{{ $primary_color }}; color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:600; padding:12px 18px; border-radius:8px;">{{ $cta['label'] ?? 'Open' }}</a>
                        </div>
                    @endif

                    @if ($closing)
                        <p style="margin:0 0 8px 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.65; color:#4b5563;">{!! nl2br(e($closing)) !!}</p>
                    @endif

                    <p style="margin:16px 0 0 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6; color:#111827;">
                        Best regards,<br><strong>{{ $site_name }}</strong>
                    </p>
                </td>
            </tr>
            <tr>
                <td style="background-color:#ffffff; border-radius:0 0 12px 12px; padding:0 28px 24px 28px;" class="mobile-padding">
                    <p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.5; color:#9ca3af;">
                        @if ($footer_note)
                            {{ $footer_note }}
                        @else
                            Need help? <a href="mailto:{{ $support_email }}" style="color:{{ $primary_color }};">{{ $support_email }}</a>
                        @endif
                    </p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
