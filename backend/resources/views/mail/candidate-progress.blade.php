<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'en' ? 'ltr' : 'rtl' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:0;background-color:#f4f7fc;color:#14413d;font-family:Tahoma,Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $description }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f7fc;"><tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background-color:#ffffff;border-top:4px solid #cc9933;border-radius:12px;">
<tr><td style="padding:32px 28px 20px;text-align:{{ $locale === 'en' ? 'left' : 'right' }};">
<img src="{{ $message->embed($logoPath) }}" alt="{{ $locale === 'en' ? 'Dr. Abdulkader Sankari Award' : 'جائزة الدكتور عبد القادر سنكري' }}" width="240" style="display:block;max-width:100%;height:auto;border:0;margin-bottom:20px;">
<p style="margin:0 0 24px;font-size:14px;line-height:1.8;color:#4b5f5c;">{{ $locale === 'en' ? 'Dr. Abdulkader Sankari Award for Culture, Heritage and Creativity' : 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع' }}</p>
<p style="margin:0 0 16px;line-height:1.8;font-size:16px;word-break:break-word;">{{ $locale === 'en' ? 'Hello' : 'مرحبًا' }} {{ $name }}{{ $locale === 'en' ? ',' : '،' }}</p>
<h1 style="margin:0 0 20px;font-size:27px;line-height:1.6;font-weight:bold;color:#14413d;">{{ $subject }}</h1>
<p style="display:inline-block;margin:0 0 20px;padding:8px 14px;background-color:#f6ecd6;color:#14413d;border-radius:6px;font-size:14px;line-height:1.8;">{{ $label }}</p>
<p style="margin:0 0 20px;font-size:16px;line-height:1.9;">{{ $description }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f7fc;border-radius:8px;">
<tr><td style="padding:18px;font-size:14px;line-height:1.9;">
{{ $locale === 'en' ? 'Application reference' : 'الرقم المرجعي للمشاركة' }}: <span dir="ltr">{{ $reference }}</span><br>
{{ $locale === 'en' ? 'Submitted version' : 'النسخة المرسلة' }}: {{ $version }}<br>
@if($when)
{{ $locale === 'en' ? 'Transition time (Damascus)' : 'وقت الانتقال بتوقيت دمشق' }}: {{ $when }}
@endif
</td></tr></table>
<p style="margin:20px 0;font-size:14px;line-height:1.9;color:#4b5f5c;">{{ $locale === 'en' ? 'This message records the transition at its stated time. Your account shows the latest status, which may have advanced since then.' : 'توثّق هذه الرسالة الانتقال في وقته المذكور. يعرض حسابك آخر حالة للملف، وقد يكون تقدّم إلى مرحلة أخرى منذ ذلك الوقت.' }}</p>
<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background-color:#14413d;border-radius:6px;text-align:center;">
<a href="{{ $actionUrl }}" style="display:inline-block;padding:15px 22px;color:#ffffff;font-size:16px;line-height:1.6;text-decoration:none;font-weight:bold;">{{ $locale === 'en' ? 'Track your application' : 'متابعة حالة ملف الترشح' }}</a>
</td></tr></table>
<p style="margin:24px 0 8px;font-size:12px;line-height:1.8;color:#4b5f5c;">{{ $locale === 'en' ? 'If the button does not work, open this link after signing in:' : 'إذا لم يعمل الزر، افتح الرابط التالي بعد تسجيل الدخول:' }}</p>
<p dir="ltr" style="margin:0;word-break:break-all;font-size:12px;line-height:1.8;"><a href="{{ $actionUrl }}" style="color:#14413d;">{{ $actionUrl }}</a></p>
</td></tr>
<tr><td style="border-top:1px solid #d5dde9;padding:20px 28px;font-size:12px;color:#4b5f5c;line-height:1.8;">{{ $locale === 'en' ? 'Award administration · Application tracking' : 'إدارة الجائزة · متابعة ملفات الترشح' }}</td></tr>
</table></td></tr></table>
</body></html>
