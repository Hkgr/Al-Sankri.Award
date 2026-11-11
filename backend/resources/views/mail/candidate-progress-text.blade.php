{{ $locale === 'en' ? 'Dr. Abdulkader Sankari Award for Culture, Heritage and Creativity' : 'جائزة الدكتور عبد القادر سنكري للثقافة والتراث والإبداع' }}

{{ $locale === 'en' ? 'Hello' : 'مرحبًا' }} {{ $name }}{{ $locale === 'en' ? ',' : '،' }}

{{ $subject }}
{{ $label }}

{{ $description }}

{{ $locale === 'en' ? 'Application reference' : 'الرقم المرجعي للمشاركة' }}: {{ $reference }}
{{ $locale === 'en' ? 'Submitted version' : 'النسخة المرسلة' }}: {{ $version }}
@if($when)
{{ $locale === 'en' ? 'Transition time (Damascus)' : 'وقت الانتقال بتوقيت دمشق' }}: {{ $when }}
@endif

{{ $locale === 'en' ? 'This message records the transition at its stated time. Your account shows the latest status, which may have advanced since then.' : 'توثّق هذه الرسالة الانتقال في وقته المذكور. يعرض حسابك آخر حالة للملف، وقد يكون تقدّم إلى مرحلة أخرى منذ ذلك الوقت.' }}

{{ $locale === 'en' ? 'Track your application (sign in first)' : 'متابعة حالة ملف الترشح (بعد تسجيل الدخول)' }}:
{{-- A server-generated text/plain URI needs literal ampersands, not HTML entities. --}}
{!! $actionUrl !!}

{{ $locale === 'en' ? 'Award administration · Application tracking' : 'إدارة الجائزة · متابعة ملفات الترشح' }}
