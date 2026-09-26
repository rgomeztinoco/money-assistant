<?php

namespace Database\Seeders;

use App\Integrations\Gmail\PreviewGmail;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\SpendingNotificationReference;
use App\Models\User;
use App\SpendingNotificationProcessingOutcome;
use Illuminate\Database\Seeder;
use LogicException;

class GmailReviewPreviewSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! config('services.gmail.preview_enabled')) {
            throw new LogicException('Gmail review preview seeding is available only in local preview mode.');
        }

        $owner = User::query()->sole();
        $connection = GmailConnection::query()->whereBelongsTo($owner, 'owner')->first();

        if ($connection !== null && $connection->gmail_account_identity !== PreviewGmail::ACCOUNT_IDENTITY) {
            throw new LogicException('The owner already has a real Gmail connection.');
        }

        $connection ??= GmailConnection::factory()->for($owner, 'owner')->create([
            'gmail_account_identity' => PreviewGmail::ACCOUNT_IDENTITY,
            'access_token' => PreviewGmail::ACCESS_TOKEN,
            'refresh_token' => 'local-preview-refresh-token',
            'history_id' => 'preview-history',
        ]);

        foreach (range(1, 24) as $number) {
            $messageId = sprintf('preview-%03d', $number);
            $discovery = GmailMessageDiscovery::query()->firstOrCreate(
                [
                    'gmail_connection_id' => $connection->id,
                    'message_id' => $messageId,
                ],
                [
                    'processed_at' => now()->subDays($number),
                    'dismissed_at' => $number > 22 ? now()->subDay() : null,
                ],
            );

            $outcome = match (true) {
                $number === 7 => SpendingNotificationProcessingOutcome::AuthenticationFailed,
                $number % 4 === 0 => SpendingNotificationProcessingOutcome::Failed,
                default => SpendingNotificationProcessingOutcome::Unsupported,
            };

            SpendingNotificationReference::query()->firstOrCreate(
                [
                    'user_id' => $owner->id,
                    'gmail_account_identity' => $connection->gmail_account_identity,
                    'message_id' => $messageId,
                ],
                [
                    'gmail_message_discovery_id' => $discovery->id,
                    'transaction_id' => null,
                    'processing_outcome' => $outcome->value,
                    'attempt_count' => 1,
                    'last_attempted_at' => now()->subDays($number),
                ],
            );
        }
    }
}
