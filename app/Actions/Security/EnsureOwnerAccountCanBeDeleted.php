<?php

namespace App\Actions\Security;

use App\Models\Category;
use App\Models\CategoryTarget;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\LearnedRule;
use App\Models\LearnedRuleBulkAction;
use App\Models\LearnedRuleChangePreview;
use App\Models\LearnedRuleSuggestion;
use App\Models\ParserProfile;
use App\Models\ReceiptBreakdown;
use App\Models\ReceiptProposal;
use App\Models\Reminder;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EnsureOwnerAccountCanBeDeleted
{
    /**
     * Payload-bearing financial tables whose records must not be erased by an
     * account-level cascade. Child tables are covered by their retained parent.
     *
     * @var list<class-string<Model>>
     */
    private const array RETAINED_MODELS = [
        Transaction::class,
        ReceiptProposal::class,
        LearnedRule::class,
        LearnedRuleSuggestion::class,
        LearnedRuleChangePreview::class,
        LearnedRuleBulkAction::class,
        DailyExchangeRate::class,
        DailyExchangeRateSeedRequest::class,
        CategoryTarget::class,
        Reminder::class,
        ParserProfile::class,
        SpendingNotificationReference::class,
    ];

    public function handle(User $owner): void
    {
        if (Category::withTrashed()->whereBelongsTo($owner, 'owner')->exists()
            || ReceiptBreakdown::withTrashed()->whereBelongsTo($owner, 'owner')->exists()) {
            $this->reject();
        }

        foreach (self::RETAINED_MODELS as $modelClass) {
            if ($modelClass::query()->whereBelongsTo($owner, 'owner')->exists()) {
                $this->reject();
            }
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages([
            'account' => 'Financial records must follow their domain trash and tombstone rules; account deletion cannot erase them.',
        ]);
    }
}
