<?php

namespace App\Models;

use Database\Factories\AccountPolicyVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A version of an account-level policy (privacy policy, terms of use),
 * independent of award cycles. Published text is never edited; a new
 * version is published instead (php artisan account-policies:publish).
 */
class AccountPolicyVersion extends Model
{
    /** @use HasFactory<AccountPolicyVersionFactory> */
    use HasFactory;

    public const PRIVACY_POLICY = 'privacy_policy';

    public const TERMS_OF_USE = 'terms_of_use';

    /**
     * Policies an account must accept before confirming its basic information.
     *
     * @var list<string>
     */
    public const REQUIRED_FOR_BASIC_PROFILE = [self::PRIVACY_POLICY, self::TERMS_OF_USE];

    /**
     * @var array<string, string> code => Arabic name, for messages
     */
    public const NAMES = [
        self::PRIVACY_POLICY => 'سياسة الخصوصية',
        self::TERMS_OF_USE => 'شروط الاستخدام',
    ];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The published version of each code, keyed by code (absent when none).
     *
     * @param  list<string>  $codes
     * @return array<string, self>
     */
    public static function currentFor(array $codes): array
    {
        return self::query()
            ->whereIn('code', $codes)
            ->where('status', self::STATUS_PUBLISHED)
            ->get()
            ->keyBy('code')
            ->all();
    }
}
