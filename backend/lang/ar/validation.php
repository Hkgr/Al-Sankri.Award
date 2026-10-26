<?php

/*
 * Arabic messages for the validation rules the public account forms use.
 * Rules not listed here fall back to English (app.fallback_locale).
 */

return [
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'email' => 'أدخل :attribute صحيحاً، مثل name@example.com.',
    'max' => [
        'string' => 'يجب ألا يزيد :attribute على :max حرفاً.',
    ],
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
