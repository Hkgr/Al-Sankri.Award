<?php

namespace App\Reports;

/**
 * «بطاقة الترشح»: the content of one submitted application as the
 * administration detail page shows it (App\Admin\SubmittedApplications::
 * show), arranged for the PDF. Stage 1 is the profile the submitted version
 * references; Stages 2–3 come from the version's immutable snapshot and its
 * version-linked attachment manifest; judging is shown separately as its
 * state at export time.
 *
 * Only names, types, sizes and scan status of attachments are listed: no
 * file is embedded, and no storage path or download address appears.
 * External links are listed as submitted text and never fetched.
 */
final class ApplicationCard
{
    public const STATUS_LABELS = [
        'submitted' => 'مُرسَل',
        'completion_requested' => 'مطلوب استكماله',
        'eligible' => 'مستوفٍ للشروط',
        'ineligible' => 'غير مستوفٍ للشروط',
        'in_judging' => 'قيد التحكيم',
        'withdrawn' => 'مسحوب',
        'closed' => 'مغلق',
    ];

    private const SCAN_LABELS = [
        'pending' => 'بانتظار الفحص (لم يُفحص بحثاً عن البرمجيات الضارة)',
        'clean' => 'سليم',
        'infected' => 'مصاب',
        'error' => 'تعذّر الفحص',
    ];

    private const KIND_LABELS = ['explanatory' => 'وثيقة شرح العمل', 'supporting' => 'وثيقة داعمة', 'video' => 'فيديو'];

    private const FORMAT_NAMES = [
        'application/pdf' => 'PDF',
        'application/msword' => 'Word (DOC)',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'Word (DOCX)',
        'video/mp4' => 'فيديو MP4',
        'video/webm' => 'فيديو WebM',
    ];

    private const GENDER_LABELS = ['male' => 'ذكر', 'female' => 'أنثى'];

    private const PROFILE_TYPES = ['individual' => 'فرد', 'institution' => 'مؤسسة'];

    /**
     * @param  array<string, mixed>  $detail
     * @return array{title: string, summary: list<array{0: string, 1: string}>, sections: list<array<string, mixed>>}
     */
    public static function build(array $detail): array
    {
        $summary = $detail['summary'];
        $details = $detail['nomination_details'] ?? null;

        return [
            'title' => 'بطاقة الترشح: '.($details['title'] ?? $summary['reference_number']),
            'summary' => [
                ['الرقم المرجعي', (string) $summary['reference_number']],
                ['الدورة', "{$summary['cycle']['name']} — {$summary['cycle']['year']}"],
                ['المجال الرئيسي', (string) ($summary['domain']['name'] ?? '—')],
                ['الفئة الفرعية', (string) ($summary['subcategory']['name'] ?? '—')],
                ['حالة الملف', self::STATUS_LABELS[$summary['status']] ?? (string) $summary['status']],
                ['التصنيف الإداري', self::review($detail['review'] ?? null)],
                ['وقت الإرسال', Formats::dateTime($summary['submitted_at']).' بتوقيت دمشق'],
                ['النسخة المرسلة', "{$summary['version_number']} · بصمة المحتوى ".substr((string) $summary['content_hash'], 0, 16)],
            ],
            'sections' => array_values(array_filter([
                self::basicInformation($detail['basic_information'] ?? null),
                self::workDetails($details),
                self::documentation($detail['documentation']),
                self::declarations($detail['declarations']),
                self::judging($detail['judging'] ?? null),
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $profile
     * @return array<string, mixed>
     */
    private static function basicInformation(?array $profile): array
    {
        $section = ['title' => 'المرحلة 1: البيانات الأساسية', 'groups' => []];
        if ($profile === null) {
            $section['note'] = 'لم يُعثر على البيانات الأساسية المشار إليها في النسخة المرسلة.';

            return $section;
        }

        $section['groups'][] = ['title' => null, 'rows' => [['نوع المتقدم', self::PROFILE_TYPES[$profile['profile_type']] ?? $profile['profile_type']]]];
        $links = implode("\n", array_map(fn (array $link): string => "{$link['platform']}: {$link['url']}", $profile['social_links'] ?? [])) ?: '—';

        if (isset($profile['individual'])) {
            $person = $profile['individual'];
            $section['groups'][] = ['title' => 'البيانات الشخصية', 'rows' => [
                ['الاسم الأول', $person['first_name']],
                ['الاسم الأوسط', $person['middle_name'] ?: '—'],
                ['الاسم الأخير', $person['last_name']],
                ['تاريخ الميلاد', Formats::date($person['birth_date'])],
                ['الجنسية', $person['nationality']['name'] ?? '—'],
                ['الجنس', self::GENDER_LABELS[$person['gender']] ?? '—'],
                ['رقم الهوية الشخصية', $person['national_id_number']],
            ]];
            $section['groups'][] = ['title' => 'الإقامة والتواصل', 'rows' => [
                ['دولة الإقامة', $person['residence_country']['name'] ?? '—'],
                ['العنوان', $person['address'] ?: '—'],
                ['رقم التواصل الأساسي', $person['primary_phone'] ?: '—'],
                ['رقم التواصل الاحتياطي', $person['secondary_phone'] ?: '—'],
                ['البريد الإلكتروني للتواصل', $person['contact_email'] ?: '—'],
                ['روابط مواقع التواصل الاجتماعي', $links],
            ]];
        } elseif (isset($profile['institution'])) {
            $institution = $profile['institution'];
            $section['groups'][] = ['title' => 'بيانات المؤسسة', 'rows' => [
                ['اسم المؤسسة', $institution['name']],
                ['الدولة', $institution['country']['name'] ?? '—'],
                ['نوع النشاط المؤسسي', $institution['activity_type'] ?: '—'],
                ['رقم الترخيص', $institution['license_number'] ?: '—'],
            ]];
            $section['groups'][] = ['title' => 'الممثل والتواصل', 'rows' => [
                ['اسم الشخص المتقدم بالنيابة عن المؤسسة', $institution['representative_name'] ?: '—'],
                ['الصفة الوظيفية', $institution['representative_title'] ?: '—'],
                ['رقم تواصل المؤسسة', $institution['phone'] ?: '—'],
                ['البريد الإلكتروني للمؤسسة', $institution['email'] ?: '—'],
                ['الموقع الإلكتروني', $institution['website'] ?: '—'],
                ['روابط مواقع التواصل الاجتماعي', $links],
            ]];
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    private static function workDetails(?array $details): array
    {
        $section = ['title' => 'المرحلة 2: فئة الترشح وبيانات العمل', 'groups' => []];
        if ($details === null) {
            $section['note'] = 'لا توجد بيانات للمرحلة 2 في النسخة المرسلة.';

            return $section;
        }

        $section['groups'][] = ['title' => 'المحور والفئة', 'rows' => [
            ['المحور', $details['domain']['name'] ?? '—'],
            ['الفئة', $details['subcategory']['name'] ?? '—'],
        ]];
        $section['groups'][] = ['title' => 'تعريف العمل وأثره', 'rows' => [
            ['عنوان العمل', $details['title']],
            ['الغاية والرسالة', $details['purpose_and_mission']],
            ['الأهداف', $details['objectives']],
            ['الأثر', $details['impact']],
        ]];

        return $section;
    }

    /**
     * The administrative classification at export time: its label, with the
     * destination committee of a referral (a legacy referral says so) or
     * the time of an exclusion. The exclusion reason is shown to
     * administration on the page, not printed on the card.
     *
     * @param  array<string, mixed>|null  $review
     */
    private static function review(?array $review): string
    {
        if ($review === null) {
            return '—';
        }

        $parts = [(string) $review['label']];
        if ($review['committee'] !== null) {
            $parts[] = (string) $review['committee']['name'];
        }
        if ($review['legacy']) {
            $parts[] = 'إحالة سابقة من سجل التحكيم';
        } elseif ($review['decided_at'] !== null) {
            $parts[] = Formats::dateTime($review['decided_at']).' بتوقيت دمشق';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $documentation
     * @return array<string, mixed>
     */
    private static function documentation(array $documentation): array
    {
        return ['title' => 'المرحلة 3: ملفات العمل وتوثيقه', 'note' => 'تُذكر الملفات بأسمائها وأنواعها وأحجامها فقط، ولا تُضمَّن في هذه البطاقة. تحقّقت المنصة من نوعها وبنيتها، ولم تُفحص بحثاً عن البرمجيات الضارة ما لم يُذكر غير ذلك.', 'groups' => [
            ['title' => 'المرفقات', 'table' => [
                'columns' => ['النوع', 'العنوان', 'اسم الملف', 'الصيغة', 'الحجم', 'حالة الفحص'],
                'rows' => array_map(fn (array $file): array => [
                    self::KIND_LABELS[$file['kind']] ?? $file['kind'],
                    $file['title'] ?: '—',
                    $file['name'],
                    self::FORMAT_NAMES[$file['mime_type']] ?? $file['mime_type'],
                    Formats::bytes((int) $file['size_bytes']),
                    self::SCAN_LABELS[$file['scan_status']] ?? $file['scan_status'],
                ], $documentation['attachments'] ?? []),
                'empty' => 'لا توجد مرفقات.',
            ]],
            ['title' => 'روابط توثيق إضافية', 'table' => [
                'columns' => ['العنوان', 'الرابط كما أُرسل'],
                'rows' => array_map(fn (array $link): array => [$link['title'], $link['url']], $documentation['links'] ?? []),
                'empty' => 'لم تُضف روابط.',
            ]],
        ]];
    }

    /**
     * @param  array<string, mixed>  $declarations
     * @return array<string, mixed>
     */
    private static function declarations(array $declarations): array
    {
        $rows = [['إقرار صحة البيانات', $declarations['data_accuracy_declared_at'] ? Formats::dateTime($declarations['data_accuracy_declared_at']).' بتوقيت دمشق' : '—']];
        foreach ($declarations['accepted_policies'] ?? [] as $policy) {
            $rows[] = [
                $policy['title'] ?? $policy['code'],
                "الإصدار {$policy['version_number']}".($policy['accepted_at'] ? ' · قُبلت في '.Formats::dateTime($policy['accepted_at']).' بتوقيت دمشق' : ''),
            ];
        }

        return ['title' => 'الإقرارات والسياسات المقبولة', 'groups' => [['title' => null, 'rows' => $rows]]];
    }

    /**
     * @param  array<string, mixed>|null  $judging
     * @return array<string, mixed>|null
     */
    private static function judging(?array $judging): ?array
    {
        if ($judging === null) {
            return null;
        }

        $section = ['title' => 'حالة التحكيم وقت التصدير', 'note' => 'هذا القسم ليس جزءاً من الملف المرسل: يعرض حالة التحكيم كما كانت لحظة إنشاء البطاقة، وقد تتغيّر بعدها.', 'groups' => []];
        if (! ($judging['ready'] ?? false)) {
            $section['groups'][] = ['title' => null, 'rows' => [['الحالة', ($judging['referred'] ?? false) ? 'لم تُعتمد لجنة تحكيم هذا المحور بعد.' : 'لم يُرسل هذا الملف إلى التحكيم؛ لا يقيّمه الحكام قبل إرسال إدارة الجائزة له.']]];

            return $section;
        }

        $section['groups'][] = ['title' => null, 'rows' => [[
            'النتيجة', $judging['decision'] !== null
                ? "متوسط التقييمات {$judging['decision']} من 100."
                : "أُرسل {$judging['received']} من {$judging['expected']}؛ يظهر المتوسط عندما يرسل كل أعضاء اللجنة.",
        ]]];
        $section['groups'][] = ['title' => 'تقييمات الحكام', 'table' => [
            'columns' => ['الحكَم', 'الدرجة', 'الاستبعاد', 'الملاحظات', 'التوصيات', 'وقت الإرسال'],
            'rows' => array_map(fn (array $score): array => [
                $score['name'],
                $score['score'] === null ? 'بانتظار الإرسال' : "{$score['score']} من 100",
                self::exclusion($score),
                $score['notes'] ?: '—',
                ($score['recommendations'] ?? null) ?: '—',
                $score['sent_at'] ? Formats::dateTime($score['sent_at']) : '—',
            ], $judging['scores'] ?? []),
            'empty' => 'لا يوجد أعضاء حاليون في اللجنة.',
        ]];

        return $section;
    }

    /**
     * A judge's own exclusion flag, as the application page shows it.
     *
     * @param  array{score: string|null, excluded?: bool}  $score
     */
    public static function exclusion(array $score): string
    {
        if ($score['score'] === null) {
            return '—';
        }

        return ($score['excluded'] ?? false) ? 'استُبعد المشارك' : 'لا';
    }
}
