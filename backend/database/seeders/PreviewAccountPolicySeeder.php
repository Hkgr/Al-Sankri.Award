<?php

namespace Database\Seeders;

use App\Models\AccountPolicyVersion;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local preview only: publishes clearly labelled, unapproved test texts of
 * the privacy policy and terms of use, so the stage 1 confirmation can be
 * tried on a development or disposable database.
 *
 *     php artisan db:seed --class=PreviewAccountPolicySeeder
 *
 * It refuses to run in production, and DatabaseSeeder never calls it.
 * Production texts are published with "php artisan account-policies:publish"
 * once the owner has approved them (docs/platform/candidate-onboarding.md).
 */
class PreviewAccountPolicySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Preview policy texts must never be published in production.');
        }

        foreach (AccountPolicyVersion::REQUIRED_FOR_BASIC_PROFILE as $code) {
            if (AccountPolicyVersion::query()->where('code', $code)->exists()) {
                continue;
            }

            AccountPolicyVersion::factory()->code($code)->published()->create([
                'body_ar' => 'نص تجريبي للمعاينة والاختبار فقط، وليس '.AccountPolicyVersion::NAMES[$code].' المعتمدة للجائزة. '
                    .'لا يُعتدّ بأي موافقة مسجّلة على هذا النص. يُستبدل بالنص المعتمد قبل الإطلاق.',
            ]);
        }
    }
}
