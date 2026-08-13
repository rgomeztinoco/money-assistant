<?php

namespace App\Actions\NotificationIngestion;

use App\Models\ParserProfile;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateParserProfile
{
    public function __construct(
        private ValidateSpendingNotificationFormat $validateFormat,
        private ProcessSpendingNotification $processSpendingNotification,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): ParserProfile
    {
        $existingProfile = isset($attributes['parser_profile_id'])
            ? ParserProfile::query()
                ->whereKey($attributes['parser_profile_id'])
                ->sole()
            : null;
        $validatedFormat = $this->validateFormat->handle(
            $attributes,
            $existingProfile,
        );

        return DB::transaction(function () use ($existingProfile, $validatedFormat): ParserProfile {
            $profile = $existingProfile === null
                ? ParserProfile::create([
                    ...$validatedFormat->profile->getAttributes(),
                    'enabled_at' => now(),
                ])
                : ParserProfile::query()
                    ->lockForUpdate()
                    ->findOrFail($existingProfile->id);

            $validatedFormat->format->forceFill([
                'parser_profile_id' => $profile->id,
                'enabled_at' => now(),
            ])->save();

            $reference = $this->processSpendingNotification->handle(
                discovery: $validatedFormat->discovery,
                message: $validatedFormat->message,
                retryUnsupported: true,
            );

            $isExpectedOutcome = $validatedFormat->format->purpose->isIgnored()
                ? $reference->processing_outcome === SpendingNotificationProcessingOutcome::Ignored->value
                : $reference->transaction_id !== null
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
}
