<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>Welcome to {{ $site_name }}!</title>
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
            .mobile-full-btn a { display: block !important; width: 100% !important; box-sizing: border-box !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f8; width:100%;">
    <div style="display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
        Welcome to {{ $site_name }} — we’re thrilled to have you on board.
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
                            Welcome
                        </div>
                        <div style="font-size:24px; line-height:1.3; font-weight:700;">
                            Welcome to {{ $site_name }}!
                        </div>
                    </div>
                </td>
            </tr>

            <tr>
                <td style="background-color:#ffffff; padding:28px 28px 8px 28px;" class="mobile-padding">
                    <p style="margin:0 0 16px 0; font-family:Arial, Helvetica, sans-serif; font-size:16px; line-height:1.6; color:#1f2937;">
                        Hi {{ $username }},
                    </p>
                    <p style="margin:0 0 20px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:1.65; color:#4b5563;">
                        Thank you for verifying your email and joining <strong style="color:#111827;">{{ $site_name }}</strong>!
                        We’re thrilled to have you on board.
                    </p>
                    <p style="margin:0 0 12px 0; font-family:Arial, Helvetica, sans-serif; font-size:15px; font-weight:700; color:#111827;">
                        Here’s what you can do next:
                    </p>
                </td>
            </tr>

            <tr>
                <td style="background-color:#ffffff; padding:0 28px 20px 28px;" class="mobile-padding">
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                        <tr>
                            <td style="padding:16px 18px; border-bottom:1px solid #e5e7eb; font-family:Arial, Helvetica, sans-serif; background-color:#ffffff;">
                                <div style="font-size:14px; font-weight:600; color:#111827; margin-bottom:8px;">1. Log in to your account</div>
                                <div class="mobile-full-btn">
                                    <a href="{{ $login_url }}" style="display:inline-block; background-color:{{ $primary_color }}; color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:600; padding:11px 18px; border-radius:8px;">
                                        Click here to log in →
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 18px; border-bottom:1px solid #e5e7eb; font-family:Arial, Helvetica, sans-serif; background-color:#f9fafb;">
                                <div style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">2. Verify your email</div>
                                <div style="font-size:13px; color:#6b7280; line-height:1.5;">
                                    Verify your email to unlock full access.
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding:16px 18px; font-family:Arial, Helvetica, sans-serif; background-color:#ffffff;">
                                <div style="font-size:14px; font-weight:600; color:#111827; margin-bottom:8px;">3. Explore resources and subscriptions</div>
                                <div style="font-size:13px; color:#6b7280; line-height:1.5; margin-bottom:10px;">
                                    Explore resources and subscriptions on the website to get started.
                                </div>
                                <div class="mobile-full-btn">
                                    <a href="{{ $explore_url }}" style="display:inline-block; background-color:{{ $secondary_color }}; color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:14px; font-weight:600; padding:11px 18px; border-radius:8px;">
                                        Explore the website →
                                    </a>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>

            <tr>
                <td style="background-color:#ffffff; border-radius:0 0 12px 12px; padding:8px 28px 28px 28px;" class="mobile-padding">
                    <p style="margin:0 0 16px 0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.65; color:#4b5563;">
                        We look forward to being the trusted partner in your journey of growth.
                        For any assistance you can always reach us at
                        <a href="mailto:{{ $support_email }}" style="color:{{ $primary_color }}; font-weight:600;">{{ $support_email }}</a>.
                    </p>
                    <p style="margin:0; font-family:Arial, Helvetica, sans-serif; font-size:14px; line-height:1.6; color:#111827;">
                        Best regards,<br>
                        <strong>{{ $site_name }}</strong>
                    </p>
                </td>
            </tr>
        </table>

        <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" width="100%" style="max-width:600px; margin:0 auto;" class="email-container">
            <tr>
                <td style="padding:20px 16px 32px 16px; text-align:center; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:1.5; color:#9ca3af;">
                    This email was sent by {{ $site_name }}.
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
