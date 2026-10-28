<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your password</title>
</head>
<body style="margin:0;padding:0;background:#0b1a3a;font-family:Arial,Helvetica,sans-serif;color:#f4f5f7;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#0b1a3a;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#12255a;border:1px solid #27407f;border-radius:18px;">
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            <p style="margin:0 0 6px;color:#ffb830;font-size:11px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">{{ $site }}</p>
                            <h1 style="margin:0 0 16px;font-size:24px;line-height:1.2;color:#ffffff;">Reset your password</h1>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#c7ccd1;">Hi {{ $user->first_name }}, someone asked for a new password for your {{ $site }} account. Press the button to choose one. Your current password keeps working until you do.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 24px;" align="left">
                            <a href="{{ $url }}" style="display:inline-block;padding:14px 26px;border-radius:12px;background:#1f6ff2;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">Choose a new password</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 32px;">
                            <p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#9aa6c4;">This link works for {{ $minutes }} minutes and once only. If you did not ask for it, ignore this message: nothing changes.</p>
                            <p style="margin:0;font-size:12px;line-height:1.6;color:#7f8bad;word-break:break-all;">If the button does not open, copy this address into your browser:<br>{{ $url }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
