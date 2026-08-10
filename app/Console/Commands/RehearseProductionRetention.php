<?php

namespace App\Console\Commands;

use App\Actions\Retention\PurgeExpiredFinancialData;
use App\Models\Category;
use App\Models\FinancialDataTombstone;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:rehearse-production-retention {gate : retention-purge or audit-tombstone}')]
#[Description('Exercise production financial-data purge and append-only tombstone enforcement')]
class RehearseProductionRetention extends Command
{
    public function handle(PurgeExpiredFinancialData $purgeExpiredFinancialData): int
    {
        $gate = (string) $this->argument('gate');

        if (! in_array($gate, ['retention-purge', 'audit-tombstone'], true)) {
            $this->components->error('The gate must be retention-purge or audit-tombstone.');

            return self::INVALID;
        }

        $owner = User::query()->oldest('id')->first();

        if ($owner === null) {
            $this->components->error('The target deployment has no owner account.');

            return self::FAILURE;
        }

        $categoryCount = Category::withTrashed()->count();
        $tombstoneCount = FinancialDataTombstone::query()->count();
        DB::beginTransaction();
        try {
            $rehearsalPassed = $this->exerciseRetention(
                $owner,
                $gate,
                $purgeExpiredFinancialData,
            );
        } finally {
            DB::rollBack();
        }

        if (! $rehearsalPassed
            || Category::withTrashed()->count() !== $categoryCount
            || FinancialDataTombstone::query()->count() !== $tombstoneCount) {
            $this->components->error('The isolated retention rehearsal failed or changed production state.');

            return self::FAILURE;
        }

        $this->line("PRODUCTION_TRUST_EVIDENCE gate={$gate} outcome=passed");

        return self::SUCCESS;
    }

    private function exerciseRetention(
        User $owner,
        string $gate,
        PurgeExpiredFinancialData $purgeExpiredFinancialData,
    ): bool {
        $category = Category::query()->create([
            'user_id' => $owner->id,
            'name' => 'production-trust-retention-payload '.Str::uuid(),
        ]);
        $category->moveToFinancialTrash();
        $category->forceFill(['purge_after' => now()->subSecond()])->save();
        $deletionId = $category->deletion_id;

        if ($deletionId === null) {
            return false;
        }

        $purgeExpiredFinancialData->handle();

        $tombstone = FinancialDataTombstone::query()->find($deletionId);
        if ($tombstone === null || Category::withTrashed()->whereKey($category->id)->exists()) {
            return false;
        }

        $serializedTombstone = json_encode($tombstone->getAttributes(), JSON_THROW_ON_ERROR);

        return ! str_contains($serializedTombstone, 'production-trust-retention-payload')
            && ($gate !== 'audit-tombstone' || $this->mutationIsRejected($deletionId));
    }

    private function mutationIsRejected(string $deletionId): bool
    {
        try {
            DB::transaction(function () use ($deletionId): void {
                DB::table('financial_data_tombstones')
                    ->where('id', $deletionId)
                    ->update(['resource_id' => DB::raw('resource_id')]);
            });
        } catch (QueryException) {
            return true;
        }

        return false;
    }
}
