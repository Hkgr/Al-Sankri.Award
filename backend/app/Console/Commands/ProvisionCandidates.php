<?php

namespace App\Console\Commands;

use App\Auth\CandidateRole;
use App\Models\User;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Classifies existing ordinary accounts as «مترشح», for accounts created
 * before new registrations received the role automatically.
 *
 * An account is eligible only when ALL of these hold:
 *
 * - it is active (disabled accounts are left alone);
 * - it has no platform role grant at all, current or revoked (a revoked
 *   grant is a deliberate decision by an administrator);
 * - it has no cycle-scoped role grant, current or revoked;
 * - it has never been a committee member (judges, integrity, trustees).
 *
 * Everything else, including staff, judges and administrators, is counted
 * and skipped, never converted. Each grant is made in its own transaction
 * with the account row locked and the rules checked again, so the command
 * is safe to rerun: a second run finds nothing to do.
 */
#[Signature('candidates:provision {--dry-run : List what would change without writing anything}')]
#[Description('Give the «مترشح» classification to existing ordinary accounts that have no role')]
class ProvisionCandidates extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CandidateRole $candidateRole): int
    {
        $candidateRole->roleId();
        $dryRun = (bool) $this->option('dry-run');

        $this->table(['Accounts', 'Count'], [
            ['All accounts', User::query()->count()],
            ['Skipped: disabled', User::query()->where('status', '!=', User::STATUS_ACTIVE)->count()],
            ['Skipped: has or had a platform role', User::query()->whereExists($this->platformGrants())->count()],
            ['Skipped: has or had a cycle role', User::query()->whereExists($this->cycleGrants())->count()],
            ['Skipped: committee member', User::query()->whereExists($this->committeeMemberships())->count()],
            ['Eligible', $this->eligible()->count()],
        ]);

        if ($dryRun) {
            $this->eligible()->orderBy('id')->each(function (User $user): void {
                $this->line("Would classify account #{$user->getKey()}");
            });
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $granted = 0;
        $this->eligible()->orderBy('id')->pluck('id')->each(function (int $id) use ($candidateRole, &$granted): void {
            $granted += DB::transaction(function () use ($id, $candidateRole): int {
                // Re-check under a lock: the account may have changed since the listing.
                $user = $this->eligible()->whereKey($id)->lockForUpdate()->first();

                return $user !== null && $candidateRole->grant($user, CandidateRole::SOURCE_PROVISIONING) ? 1 : 0;
            });
        });

        $this->info("Classified {$granted} account(s) as «".CandidateRole::NAME.'».');

        return self::SUCCESS;
    }

    /**
     * @return EloquentBuilder<User>
     */
    private function eligible(): EloquentBuilder
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotExists($this->platformGrants())
            ->whereNotExists($this->cycleGrants())
            ->whereNotExists($this->committeeMemberships());
    }

    private function platformGrants(): Closure
    {
        return fn (Builder $query) => $query->selectRaw('1')->from('user_roles')->whereColumn('user_roles.user_id', 'users.id');
    }

    private function cycleGrants(): Closure
    {
        return fn (Builder $query) => $query->selectRaw('1')->from('cycle_user_roles')->whereColumn('cycle_user_roles.user_id', 'users.id');
    }

    private function committeeMemberships(): Closure
    {
        return fn (Builder $query) => $query->selectRaw('1')->from('committee_members')->whereColumn('committee_members.user_id', 'users.id');
    }
}
