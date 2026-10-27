<?php

namespace Database\Factories;

use App\Models\AccountPolicyVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test fixtures only. Their text is clearly marked as an unapproved test
 * text and must never be published in production.
 *
 * @extends Factory<AccountPolicyVersion>
 */
class AccountPolicyVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => AccountPolicyVersion::PRIVACY_POLICY,
            'version_number' => 1,
            'title_ar' => 'نص تجريبي للاختبار (غير معتمد)',
            'body_ar' => 'هذا نص تجريبي يُستخدم في الاختبارات فقط، وليس سياسة معتمدة.',
            'status' => AccountPolicyVersion::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    public function code(string $code): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => $code,
            'title_ar' => AccountPolicyVersion::NAMES[$code].' (نص تجريبي غير معتمد)',
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountPolicyVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountPolicyVersion::STATUS_RETIRED,
            'published_at' => now()->subDay(),
        ]);
    }
}
