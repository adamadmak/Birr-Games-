<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirm your email</title>
</head>
<body style="margin:0;padding:0;background:#0b0b0c;font-family:Arial,Helvetica,sans-serif;color:#f4f5f7;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#0b0b0c;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#15171a;border:1px solid #2a2d31;border-radius:18px;">
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <p style="margin:0 0 6px;color:#40f6b0;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">{{ $site }}</p>
                            <h1 style="margin:0 0 16px;font-size:24px;line-height:1.2;color:#ffffff;">Confirm your email address</h1>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#c7ccd1;">Hi {{ $user->first_name }}, thanks for joining {{ $site }}. Press the button below to confirm that this address belongs to you.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 24px;" align="left">
                            <a href="{{ $url }}" style="display:inline-block;padding:14px 26px;border-radius:12px;background:#17e495;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">Confirm email</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px;">
                            <p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#8d949b;">This link works for {{ $hours }} hours. If you did not create an account, you can ignore this message and nothing will change.</p>
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#6b7279;word-break:break-all;">If the button does not open, copy this address into your browser:<br>{{ $url }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
