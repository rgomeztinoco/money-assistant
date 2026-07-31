<?php

namespace App\Actions\NotificationIngestion;

use App\Models\ParserProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApproveParserProfileVersion
{
    public function __construct(
        private BuildParserProfileProposal $buildProposal,
        private ProcessSpendingNotification $processSpendingNotification,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $owner, array $attributes): ParserProfile
    {
        $proposal = $this->buildProposal->handle($owner, $attributes);

        return DB::transaction(function () use ($owner, $proposal): ParserProfile {
            if ($proposal->existingProfile === null) {
                $profile = ParserProfile::create([
                    'user_id' => $owner->getKey(),
                    'name' => $proposal->profileName,
                    'current_version' => 1,
                    'enabled_at' => now(),
                ]);
            } else {
                $profile = ParserProfile::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->lockForUpdate()
                    ->findOrFail($proposal->existingProfile->id);
                $proposal->profileVersion->version = $profile->current_version + 1;
            }

            $proposal->profileVersion->parser_profile_id = $profile->id;
            $proposal->profileVersion->save();
            $proposal->format->parser_profile_version_id = $proposal->profileVersion->id;
            $proposal->format->save();
            $profile->forceFill([
                'current_version' => $proposal->profileVersion->version,
                'enabled_at' => now(),
            ])->save();

            $reference = $this->processSpendingNotification->handle(
                owner: $owner,
                discovery: $proposal->discovery,
                message: $proposal->message,
            );

            if ($reference === null
                || $reference->transaction_id === null
                || ! in_array(
                    $reference->processing_outcome,
                    ['created', 'created_with_review'],
                    true,
                )) {
                throw new InvalidArgumentException(
                    'This message overlaps another profile and cannot be confirmed safely.',
                );
            }

            return $profile;
        }, 3);
    }
}
