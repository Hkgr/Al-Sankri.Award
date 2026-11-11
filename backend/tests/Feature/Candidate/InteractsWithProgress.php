<?php

namespace Tests\Feature\Candidate;

use App\Admin\AdminAccess;
use App\Admin\JudgingCommittees;
use App\Candidates\Documentation;
use App\Models\ApplicantIndividualDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

trait InteractsWithProgress
{
    use InteractsWithNominationStage;

    private int $progressApplicationId;

    private int $progressCycleId;

    private int $progressCommitteeId;

    private function prepareProgress(bool $submit = true): void
    {
        Queue::fake();
        $this->enforceCsrfProtection();
        $this->operator = User::factory()->create(['email' => 'progress-operator@example.org']);
        $this->candidate = User::factory()->candidate()->create(['email' => 'progress-owner@example.org']);
        $this->configureCycle();
        app(AdminAccess::class)->grantAdmin($this->operator);
        $this->progressCycleId = (int) DB::table('award_cycles')->where('code', 'cycle-2026')->value('id');
        ['applicant_id' => $applicant] = $this->completeStage1($this->candidate);
        (new ApplicantIndividualDetail)->forceFill(['applicant_id' => $applicant, 'first_name' => 'Test', 'last_name' => 'Owner', 'birth_date' => '1990-04-02', 'nationality_country_code' => 'SY', 'gender' => 'female', 'national_id_number' => '04019876543'])->save();
        $this->signIn($this->candidate->email);
        $this->confirm($this->payload())->assertCreated();
        $this->progressApplicationId = (int) DB::table('applications')->where('created_by', $this->candidate->id)->value('id');
        DB::table('candidate_nominations')->where('application_id', $this->progressApplicationId)->update(['documentation_confirmed_revision' => 0, 'documentation_confirmed_at' => now()]);
        $file = DB::table('files')->insertGetId(['uploaded_by' => $this->candidate->id, 'storage_disk' => 'candidate_evidence', 'storage_path' => 'progress.pdf', 'original_name' => 'work.pdf', 'detected_mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'scan_status' => 'clean', 'scanned_at' => now(), 'access_level' => 'private']);
        DB::table('file_links')->insert(['file_id' => $file, 'application_id' => $this->progressApplicationId, 'purpose' => Documentation::KINDS['explanatory']['purpose']]);
        if ($submit) {
            $this->send('/api/candidate/submission', ['reviewed_revision' => 0])->assertCreated();
        }
    }

    /** @return list<User> */
    private function prepareProgressCommittee(int $count = 3): array
    {
        $committees = app(JudgingCommittees::class);
        [$outcome, $id] = $committees->create($this->operator, $this->progressCycleId, 'creativity', 'INTERNAL-COMMITTEE');
        $this->assertSame(JudgingCommittees::CREATED, $outcome);
        $this->progressCommitteeId = (int) $id;
        $judges = [];
        for ($i = 0; $i < $count; $i++) {
            $judge = User::factory()->create(['name' => 'INTERNAL-JUDGE-'.$i]);
            $lookup = $committees->lookup($this->progressCycleId, $judge->email);
            [$appointed] = $committees->appoint($this->operator, $this->progressCycleId, 'creativity', ['email' => $judge->email, 'name' => $judge->name, 'phone' => null, 'specialty' => null], true, $lookup['account_ref']);
            $this->assertSame(JudgingCommittees::APPOINTED, $appointed);
            $judges[] = $judge;
        }
        $this->assertSame(JudgingCommittees::UPDATED, $committees->approve($this->operator, $this->progressCycleId, 'creativity'));

        return $judges;
    }
}
