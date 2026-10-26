<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading }}</title>
</head>
{{-- Account emails: Arabic, right to left, brand colours only where they meet contrast (deep green on light). --}}
<body style="margin:0;padding:0;background:#F4F7FC;color:#14413D;font-family:Tahoma,'Segoe UI',Arial,sans-serif;line-height:1.8;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F7FC;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#FFFFFF;border:1px solid #D5DDE9;border-top:4px solid #CC9933;border-radius:8px;" dir="rtl">
                    <tr>
                        <td style="padding:32px 28px;text-align:right;">
                            <p style="margin:0 0 8px;font-size:14px;color:#4B5F5C;">{{ $awardName }}</p>
                            <h1 style="margin:0 0 16px;font-size:22px;line-height:1.4;color:#14413D;">{{ $heading }}</h1>
                            <p style="margin:0 0 24px;font-size:16px;">{{ $intro }}</p>
                            <p style="margin:0 0 24px;">
                                <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 24px;background:#14413D;color:#F4F7FC;border-radius:4px;font-size:16px;font-weight:bold;text-decoration:none;">{{ $actionText }}</a>
                            </p>
                            <p style="margin:0 0 16px;font-size:14px;color:#4B5F5C;">{{ $outro }}</p>
                            <p style="margin:0;font-size:14px;color:#4B5F5C;">إذا لم يعمل الزر، فانسخ هذا الرابط في المتصفح:</p>
                            <p style="margin:4px 0 0;font-size:13px;direction:ltr;text-align:left;word-break:break-all;"><a href="{{ $actionUrl }}" style="color:#14413D;">{{ $actionUrl }}</a></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
