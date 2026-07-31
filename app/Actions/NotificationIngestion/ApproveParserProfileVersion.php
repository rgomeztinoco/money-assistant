<?php

namespace App\Actions\NotificationIngestion;

use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationFormat;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                $previousVersion = null;
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
                $previousVersion = $profile->versions()
                    ->where('version', $profile->current_version)
                    ->with('formats')
                    ->sole();
                $proposal->profileVersion->version = $profile->current_version + 1;
            }

            $proposal->profileVersion->parser_profile_id = $profile->id;
            $proposal->profileVersion->save();
            $this->carryForwardFormats(
                $previousVersion,
                $proposal->profileVersion,
                $proposal->format,
            );
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
                retryUnsupported: true,
            );

            $isExpectedOutcome = $proposal->format->purpose->isIgnored()
                ? $reference?->processing_outcome === SpendingNotificationProcessingOutcome::Ignored->value
                : $reference?->transaction_id !== null
                    && in_array(
                        $reference->processing_outcome,
                        SpendingNotificationProcessingOutcome::successValues(),
                        true,
                    );

            if (! $isExpectedOutcome) {
                throw new InvalidArgumentException(
                    'This message overlaps another profile and cannot be confirmed safely.',
                );
            }

            return $profile;
        }, 3);
    }

    private function carryForwardFormats(
        ?ParserProfileVersion $previousVersion,
        ParserProfileVersion $newVersion,
        SpendingNotificationFormat $newFormat,
    ): void {
        if ($previousVersion === null) {
            return;
        }

        foreach ($previousVersion->formats as $format) {
            if (Str::lower($format->name) === Str::lower($newFormat->name)
                || hash_equals($format->rule_identifier, $newFormat->rule_identifier)) {
                continue;
            }

            SpendingNotificationFormat::create([
                'parser_profile_version_id' => $newVersion->id,
                'name' => $format->name,
                'mime_source' => $format->mime_source,
                'purpose' => $format->purpose->value,
                'rule_identifier' => $format->rule_identifier,
                'definition' => $format->definition,
            ]);
        }
    }
}
