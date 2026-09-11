<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>[{{ $site_name }}] New User Registration</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        html, body { margin: 0 !important; padding: 0 !important; height: 100% !important; width: 100% !important; }
        * { -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt !important; mso-table-rspace: 0pt !important; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; display: block; }
        a { text-decoration: none; }
        @media only screen and (max-width: 620px) {
            .email-container { width: 100% !important; max-width: 100% !important; }
            .mobile-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .summary-label, .summary-value { display: block !important; width: 100% !important; text-align: left !important; }
            .summary-value { margin-top: 4px !important; margin-bottom: 12px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; width:100%;">
    <div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
        New user has registered on {{ $site_name }}: {{ $username }}
    </div>

    <center role="article" aria-roledescription="email" lang="en" style="width:100%; background-color:#f4f6f8;">
        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:600px; margin:0 auto;" class="email-container">
            <tr>
                <td style="padding:24px 16px 12px 16px; text-align:center;">
                    @if ($logo_url)
                        <img src="{{ $logo_url }}" alt="{{ $site_name }}" width="160" style="max-width:160px; height:auto; margin:0 auto;">
                    @else
                        <div style="font-family:Arial, Helvetica, sans-serif; font-size:22px; font-weight:700; color:{{ $primary_color }};">
                            {{ $site_name }}
                        </div>
                    @endif
                </td>
            </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:600px; margin:0 auto;" class="email-container">
            <tr>
                <td style="background-color:{{ $primary_color }}; border-radius:12px 12px 0 0; padding:28px 28px 24px 28px;" class="mobile-padding">
                    <div style="font-family:Arial, Helvetica, sans-serif; color:#ffffff;">
                        <div style="font-size:13px; letter-spacing:0.08em; text-transform:uppercase; opacity:0.9; margin-bottom:8px;">
                            Admin notification
                        </div>
                        <div style="font-size:22px; line-height:1.3; font-weight:700;">
                            New User Registration
                        </div>
                    </div>
                </td>
            </tr>

            <tr>
                <td style="background-color:#ffffff; padding:28px 28px 16px 28px;" class="mobile-padding">
                    <p style="margin:0 0 20px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.65; color:#4b5563;">
                        New User has registered on <strong style="color:#111827;">{{ $site_name }}</strong>
                    </p>

                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f9fafb; border:1px solid #e5e7eb; border-radius:10px;">
                        <tr>
                            <td style="padding:18px 20px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:14px; color:#374151;">
                                    <tr>
                                        <td class="summary-label" style="padding:8px 0; color:#6b7280; width:35%; vertical-align:top;">Username</td>
                                        <td class="summary-value" style="padding:8px 0; font-weight:600; color:#111827; text-align:right;">{{ $username }}</td>
                                    </tr>
                                    <tr>
                                        <td class="summary-label" style="padding:8px 0; border-top:1px solid #e5e7eb; color:#6b7280; vertical-align:top;">E-mail</td>
                                        <td class="summary-value" style="padding:8px 0; border-top:1px solid #e5e7eb; font-weight:600; color:#111827; text-align:right;">
                                            <a href="mailto:{{ $user_email }}" style="color:{{ $primary_color }};">{{ $user_email }}</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr>
                <td style="background-color:#ffffff; border-radius:0 0 12px 12px; padding:0 28px 28px 28px;" class="mobile-padding">
                    <p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:13px; line-height:1.5; color:#9ca3af;">
                        You received this because your role has “Receive admin emails” enabled for {{ $site_name }}.
                    </p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
