<?php

namespace App\Http\Requests\Candidate;

use App\Candidates\Documentation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One Stage 3 file per request: its kind, the file, an optional short
 * title and the browser's operation identifier for this upload (a retry
 * sends the same one). The file's size, type and structure are checked by
 * EvidenceInspector, with exact byte limits.
 */
class DocumentationUploadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(array_keys(Documentation::KINDS))],
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:150', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    $fail('يحتوي العنوان على رموز تحكّم غير مسموح بها. اكتب نصاً عادياً.');
                }
            }],
            'operation_id' => ['required', 'string', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind.*' => 'نوع المرفق غير معروف.',
            'file.required' => 'اختر ملفاً لرفعه.',
            'file.file' => 'تعذّر استلام الملف. قد يكون أكبر من الحد المسموح، أو انقطع الرفع. أعد المحاولة.',
            'file.uploaded' => 'تعذّر استلام الملف. قد يكون أكبر من الحد المسموح، أو انقطع الرفع. أعد المحاولة.',
            'title.max' => 'العنوان أطول من 150 حرفاً.',
            'operation_id.*' => 'تعذّر تحديد عملية الرفع. أعد تحميل الصفحة ثم حاول مرة أخرى.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($title = $this->input('title'))) {
            $title = Str::squish($title);
            $this->merge(['title' => $title === '' ? null : $title]);
        }
    }
}
