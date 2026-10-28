<?php

namespace App\Http\Controllers\Admin;

use App\Admin\AdminCycles;
use App\Admin\JudgingCommittees;
use App\Candidates\NominationTaxonomy;
use App\Http\Controllers\Controller;
use App\Support\PhoneNumbers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * «لجان الحكام» for administrators (committees.manage_judging): the three
 * main-domain judging committees of one cycle, their members, and the
 * appointment of judges. The administrator is always the signed-in account;
 * no actor, role or permission is read from the request.
 */
class CommitteesController extends Controller
{
    /**
     * Refusals: [HTTP status, message].
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const REFUSALS = [
        JudgingCommittees::DOMAIN_UNKNOWN => [404, 'هذا المجال الرئيسي غير موجود في الدورة المختارة.'],
        JudgingCommittees::COMMITTEE_EXISTS => [409, 'لهذا المجال لجنة تحكيم قائمة في هذه الدورة. لم يُنشأ شيء.'],
        JudgingCommittees::COMMITTEE_MISSING => [409, 'لا توجد لجنة تحكيم لهذا المجال في هذه الدورة بعد. أنشئ اللجنة أولاً، ثم أضف إليها الحكام.'],
        JudgingCommittees::COMMITTEE_APPROVED => [409, 'اللجنة معتمدة، ولا تتغيّر عضويتها وهي معتمدة. أعدها إلى المسودة أولاً إن أردت تغيير أعضائها.'],
        JudgingCommittees::COMMITTEE_NOT_APPROVED => [409, 'اللجنة ليست معتمدة. لم يتغيّر شيء.'],
        JudgingCommittees::ACCOUNT_EXISTS => [409, 'يوجد حساب مسجّل بهذا البريد. راجع بياناته أدناه، ثم أكّد تعيينه إن كان هو المقصود.'],
        JudgingCommittees::ACCOUNT_MISSING => [409, 'لم يعد هذا الحساب موجوداً بهذا البريد. تحقّق من البريد ثم أعد المحاولة.'],
        JudgingCommittees::ACCOUNT_CHANGED => [409, 'لم يعد البريد يشير إلى الحساب الذي عُرض عليك للتأكيد، فلم يُعيَّن أحد. ابحث عن البريد من جديد، وتحقّق من الحساب، ثم أكّد.'],
        JudgingCommittees::ACCOUNT_DISABLED => [409, 'هذا الحساب معطّل، فلا يُعيَّن حكَماً. لم يتغيّر شيء، ولم يُفعَّل الحساب.'],
        JudgingCommittees::ALREADY_JUDGE => [409, 'هذا الشخص حكَم في لجنة تحكيم أخرى من لجان هذه الدورة، ولا يكون للحكَم إلا لجنة مجال رئيسي واحدة في الدورة.'],
        JudgingCommittees::SUBMITTED_CANDIDATE => [409, 'لصاحب هذا الحساب مشاركة مرسلة في هذه الدورة، فلا يُعيَّن حكَماً فيها (تعارض مصالح). لم يتغيّر شيء، ولم تُمسّ مشاركته.'],
        JudgingCommittees::MEMBER_NOT_FOUND => [404, 'لم يُعثر على هذه العضوية الحالية في لجنة هذا المجال.'],
        JudgingCommittees::JUDGING_HISTORY => [409, 'لهذه اللجنة أو العضوية سجل تحكيم قائم، فلا يمكن هذا التغيير.'],
        JudgingCommittees::WRONG_SIZE => [422, 'يتطلّب اعتماد اللجنة 3 أو 5 حكام حاليين بالضبط.'],
        JudgingCommittees::INACTIVE_MEMBERS => [422, 'في اللجنة حكَم حسابه معطّل. أنهِ عضويته أو استبدله قبل الاعتماد.'],
    ];

    public function __construct(private readonly JudgingCommittees $committees, private readonly AdminCycles $cycles) {}

    public function index(int $cycle): JsonResponse
    {
        return $this->cycles->exists($cycle) ? response()->json(['data' => $this->committees->overview($cycle)]) : $this->refusal(JudgingCommittees::DOMAIN_UNKNOWN);
    }

    public function store(Request $request, int $cycle): JsonResponse
    {
        $validated = $request->validate([
            'domain' => ['required', Rule::in(NominationTaxonomy::domainCodes())],
            'name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
        ], ['name.*' => 'اكتب اسم اللجنة (حتى 255 حرفاً).', 'domain.*' => 'اختر مجالاً رئيسياً.']);

        [$outcome] = $this->committees->create($request->user(), $cycle, $validated['domain'], Str::squish($validated['name']));

        return $outcome === JudgingCommittees::CREATED ? $this->state($cycle, 'أُنشئت اللجنة مسودةً.', 201) : $this->refusal($outcome);
    }

    public function lookup(Request $request, int $cycle): JsonResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email')]);
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']], ['email.*' => 'اكتب بريداً إلكترونياً صحيحاً.']);

        return response()->json(['data' => ['account' => $this->committees->lookup($cycle, $validated['email'])]]);
    }

    public function appoint(Request $request, int $cycle): JsonResponse
    {
        $request->merge(['email' => is_string($request->input('email')) ? Str::lower(trim($request->input('email'))) : $request->input('email')]);
        $existing = $request->boolean('existing');
        $validated = $request->validate([
            'domain' => ['required', Rule::in(NominationTaxonomy::domainCodes())],
            'email' => ['required', 'string', 'email', 'max:255'],
            'existing' => ['required', 'boolean'],
            // The binding of the confirmed lookup (JudgingCommittees::accountRef).
            'account_ref' => [Rule::requiredIf($existing), 'nullable', 'string', 'size:64'],
            // A new account's name and phone; an existing account keeps its own.
            'name' => [Rule::requiredIf(! $existing), 'nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'phone' => ['nullable', 'string', 'max:32'],
            'specialty' => ['nullable', 'string', 'max:150', 'not_regex:/[\x00-\x1F\x7F]/'],
        ], [
            'domain.*' => 'اختر المجال الرئيسي للحكَم.',
            'email.*' => 'اكتب بريداً إلكترونياً صحيحاً.',
            'account_ref.*' => 'ابحث عن البريد من جديد، ثم أكّد الحساب المعروض.',
            'name.*' => 'اكتب الاسم الكامل (حتى 255 حرفاً).',
            'specialty.*' => 'التخصص حتى 150 حرفاً.',
        ]);

        $phone = null;
        if (! $existing && filled($validated['phone'] ?? null)) {
            $phone = PhoneNumbers::internationalToE164($validated['phone']);
            if ($phone === null) {
                return response()->json(['message' => 'تحقّق من رقم الهاتف.', 'errors' => ['phone' => ['اكتب الرقم بصيغته الدولية، مثل ‎+963 9xx xxx xxx.']]], 422);
            }
        }

        [$outcome, $result] = $this->committees->appoint($request->user(), $cycle, $validated['domain'], [
            'name' => Str::squish((string) ($validated['name'] ?? '')),
            'email' => $validated['email'],
            'phone' => $phone,
            'specialty' => filled($validated['specialty'] ?? null) ? Str::squish($validated['specialty']) : null,
        ], $existing, $existing ? $validated['account_ref'] : null);

        if ($outcome !== JudgingCommittees::APPOINTED) {
            return $this->refusal($outcome, $result === null ? [] : ['account' => $result]);
        }

        $message = match ($result['setup_link']) {
            null => 'عُيِّن الحساب حكَماً في اللجنة.',
            JudgingCommittees::LINK_SENT => 'أُنشئ الحساب وعُيِّن حكَماً، وأُرسل إلى بريده رابط لتعيين كلمة المرور.',
            JudgingCommittees::LINK_THROTTLED => 'أُنشئ الحساب وعُيِّن حكَماً. لم يُرسل رابط تعيين كلمة المرور الآن لأن رابطاً أُرسل قبل قليل؛ يمكنك إعادة الإرسال بعد دقيقة.',
            default => 'أُنشئ الحساب وعُيِّن حكَماً، لكن تعذّر إرسال رابط تعيين كلمة المرور. الحساب والتعيين محفوظان؛ أعد إرسال الرابط من جدول الأعضاء.',
        };

        return $this->state($cycle, $message, 201, ['appointment' => $result]);
    }

    public function end(Request $request, int $cycle, string $domain, int $member): JsonResponse
    {
        $outcome = $this->committees->end($request->user(), $cycle, $domain, $member);

        return $outcome === JudgingCommittees::UPDATED ? $this->state($cycle, 'أُنهيت العضوية، وبقيت في سجل اللجنة.') : $this->refusal($outcome);
    }

    public function approve(Request $request, int $cycle, string $domain): JsonResponse
    {
        $outcome = $this->committees->approve($request->user(), $cycle, $domain);

        return $outcome === JudgingCommittees::UPDATED ? $this->state($cycle, 'اعتُمدت اللجنة.') : $this->refusal($outcome);
    }

    public function returnToDraft(Request $request, int $cycle, string $domain): JsonResponse
    {
        $outcome = $this->committees->returnToDraft($request->user(), $cycle, $domain);

        return $outcome === JudgingCommittees::UPDATED ? $this->state($cycle, 'أُعيدت اللجنة إلى المسودة، ويمكن الآن تعديل أعضائها.') : $this->refusal($outcome);
    }

    public function setupLink(int $cycle, string $domain, int $member): JsonResponse
    {
        return match ($this->committees->resendSetupLink($cycle, $domain, $member)) {
            JudgingCommittees::LINK_SENT => response()->json(['code' => 'sent', 'message' => 'أُرسل رابط تعيين كلمة المرور إلى بريد الحكَم.']),
            JudgingCommittees::LINK_THROTTLED => response()->json(['code' => 'throttled', 'message' => 'أُرسل رابط قبل قليل. انتظر دقيقة ثم أعد المحاولة.'], 429),
            JudgingCommittees::LINK_FAILED => response()->json(['code' => 'mail_failed', 'message' => 'تعذّر إرسال الرابط الآن. الحساب والتعيين محفوظان؛ أعد المحاولة لاحقاً.'], 503),
            default => response()->json(['code' => 'not_applicable', 'message' => 'لا حاجة إلى رابط إعداد لهذا الحساب: له كلمة مرور أو دخول عبر Google، أو أنه ليس عضواً في هذه اللجنة.'], 409),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function state(int $cycle, string $message, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $this->committees->overview($cycle)] + $extra, $status);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function refusal(string $outcome, array $extra = []): JsonResponse
    {
        [$status, $message] = self::REFUSALS[$outcome] ?? [409, 'تعذّر تنفيذ الطلب. أعد تحميل الصفحة ثم حاول مرة أخرى.'];

        return response()->json(['code' => $outcome, 'message' => $message] + $extra, $status);
    }
}
