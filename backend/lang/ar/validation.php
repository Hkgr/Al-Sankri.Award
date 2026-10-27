<?php

/*
 * Arabic messages for the validation rules the public account forms use.
 * Rules not listed here fall back to English (app.fallback_locale).
 */

return [
    'accepted' => 'يجب تأكيد :attribute.',
    'accepted_if' => 'يجب تأكيد :attribute.',
    'array' => 'قيمة :attribute غير صالحة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date_format' => 'أدخل :attribute بصيغة صحيحة.',
    'email' => 'أدخل :attribute صحيحاً، مثل name@example.com.',
    'in' => 'اختر :attribute من القائمة.',
    'integer' => 'قيمة :attribute غير صالحة.',
    'max' => [
        'array' => 'يمكن إضافة :max عناصر على الأكثر في :attribute.',
        'string' => 'يجب ألا يزيد :attribute على :max حرفاً.',
    ],
    'prohibited' => 'لا يُقبل :attribute هنا.',
    'prohibited_unless' => 'لا يُقبل :attribute مع الاختيار الحالي.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required_if' => 'أدخل :attribute.',
    'required_with' => 'أدخل :attribute.',
    'url' => 'أدخل رابطاً كاملاً يبدأ بـ https:// أو http://.',
    'min' => [
        'string' => 'يجب أن تتكوّن :attribute من :min أحرف على الأقل.',
    ],
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute هذه في تسريب بيانات. اختر كلمة مرور أخرى.',
    ],
    'required' => 'أدخل :attribute.',
    'string' => 'يجب أن يكون :attribute نصاً.',
    'unique' => ':attribute مستخدم مسبقاً.',

    'attributes' => [
        'name' => 'الاسم الكامل',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'token' => 'رمز إعادة التعيين',
    ],
];
