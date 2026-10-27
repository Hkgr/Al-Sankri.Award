<?php

namespace App\Http\Requests\Candidate;

use App\Models\DocumentationLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The full list of Stage 3 documentation links: a title and an HTTPS
 * address per row. Only https:// is accepted (no javascript:, data:, file:
 * or other schemes), with a host and without embedded credentials
 * (user:password@). The address is kept exactly as validated; its content
 * is never fetched.
 */
class DocumentationLinksRequest extends FormRequest
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
            'links' => ['present', 'array', 'max:'.DocumentationLink::MAX_ROWS],
            'links.*' => ['array'],
            'links.*.title' => ['required', 'string', 'max:150', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    $fail('يحتوي عنوان الرابط على رموز تحكّم غير مسموح بها.');
                }
            }],
            'links.*.url' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! self::isSafeHttpsUrl($value)) {
                    $fail('أدخل رابطاً كاملاً يبدأ بـ https:// دون اسم مستخدم أو كلمة مرور، مثل https://example.org/video.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'links.max' => 'يمكن إضافة '.DocumentationLink::MAX_ROWS.' رابطاً على الأكثر.',
            'links.*.title.required' => 'أدخل عنواناً للرابط.',
            'links.*.title.max' => 'عنوان الرابط أطول من 150 حرفاً.',
            'links.*.url.required' => 'أدخل الرابط.',
            'links.*.url.max' => 'الرابط أطول من 2048 حرفاً.',
        ];
    }

    public static function isSafeHttpsUrl(string $url): bool
    {
        if (preg_match('/[\s\x00-\x1F\x7F\\\\]/u', $url) === 1) {
            return false;
        }

        // Addresses with Arabic or other non-ASCII characters are checked in
        // their encoded form (punycode host, percent-encoded rest); the
        // address itself is kept as the candidate wrote it.
        $parts = parse_url($url);
        if (! is_array($parts) || filter_var(self::asciiForm($url, $parts), FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function asciiForm(string $url, array $parts): string
    {
        $host = (string) ($parts['host'] ?? '');
        if ($host !== '' && preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if ($ascii === false) {
                return '';
            }
            $url = str_replace($host, $ascii, $url);
        }

        return (string) preg_replace_callback('/[^\x00-\x7F]+/u', fn (array $match): string => rawurlencode($match[0]), $url);
    }

    protected function prepareForValidation(): void
    {
        $links = $this->input('links');
        if (! is_array($links)) {
            return;
        }

        foreach ($links as $index => $link) {
            if (is_array($link)) {
                $links[$index]['title'] = is_string($link['title'] ?? null) ? Str::squish($link['title']) : ($link['title'] ?? null);
                $links[$index]['url'] = is_string($link['url'] ?? null) ? trim($link['url']) : ($link['url'] ?? null);
            }
        }
        $this->merge(['links' => $links]);
    }

    /**
     * @return list<array{title: string, url: string}>
     */
    public function links(): array
    {
        return array_values(array_map(fn (array $link): array => Arr::only($link, ['title', 'url']), $this->validated('links')));
    }
}
