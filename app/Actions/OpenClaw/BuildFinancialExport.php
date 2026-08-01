<?php

namespace App\Actions\OpenClaw;

use App\Models\AiCategoryProposal;
use App\Models\AiClassificationRequest;
use App\Models\AiClassificationValidationContext;
use App\Models\Category;
use App\Models\CategoryAssignment;
use App\Models\CategoryTarget;
use App\Models\CategoryTargetRevision;
use App\Models\DailyExchangeRate;
use App\Models\DailyExchangeRateSeedRequest;
use App\Models\FinancialDataTombstone;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\IntegrationIncident;
use App\Models\LearnedRule;
use App\Models\LearnedRuleBulkAction;
use App\Models\LearnedRuleBulkActionItem;
use App\Models\LearnedRuleChangePreview;
use App\Models\LearnedRuleRevision;
use App\Models\LearnedRuleSuggestion;
use App\Models\LearnedRuleSuggestionEvidence;
use App\Models\LineItem;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\ReceiptBreakdown;
use App\Models\ReceiptProposal;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\ReminderLifecycleEvent;
use App\Models\SpendingNotificationFormat;
use App\Models\SpendingNotificationReference;
use App\Models\SuspectedDuplicate;
use App\Models\SuspectedDuplicateReceiptBreakdownMove;
use App\Models\SuspectedDuplicateResolution;
use App\Models\SuspectedDuplicateSourceMove;
use App\Models\Transaction;
use App\Models\TransactionCorrection;
use App\Models\TransactionStateChange;
use App\Models\User;
use HashContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use RuntimeException;
use Throwable;

final class BuildFinancialExport
{
    /** @throws JsonException */
    public function handle(User $owner): FinancialExportArtifact
    {
        $stream = tmpfile();

        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary financial export.');
        }

        try {
            $digest = hash_init('sha256');
            $ownerJson = json_encode([
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'reporting_currency' => $owner->reporting_currency?->value,
                'created_at' => $owner->created_at?->toIso8601String(),
                'updated_at' => $owner->updated_at?->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $this->writeState($stream, $digest, '{"schema_version":1,"owner":'.$ownerJson);

            $transactionCount = $this->writeDataset(
                $stream,
                $digest,
                'transactions',
                Transaction::query()->whereBelongsTo($owner, 'owner'),
                [
                    'id', 'user_id', 'occurred_on', 'amount_minor', 'currency', 'kind',
                    'merchant_description', 'payment_instrument_label', 'payment_instrument_last_four',
                    'confirmed_at', 'revision', 'provisional_fields', 'voided_at', 'original_purchase_id',
                    'refund_relationship_review_reasons', 'category_id', 'category_assignment_provenance',
                    'created_at', 'updated_at',
                ],
            );
            $this->writeDataset($stream, $digest, 'transaction_corrections', TransactionCorrection::query()
                ->whereIn('transaction_id', $this->ownerIds(Transaction::class, $owner)), [
                    'id', 'transaction_id', 'field', 'previous_value', 'corrected_value',
                    'transaction_revision', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'transaction_state_changes', TransactionStateChange::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'transaction_id', 'idempotency_key', 'operation', 'expected_revision',
                    'result_revision', 'result_voided_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'category_assignments', CategoryAssignment::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'transaction_id', 'category_id', 'previous_category_id', 'source',
                    'is_correction', 'transaction_revision', 'linked_purchase_id', 'learned_rule_id',
                    'learned_rule_revision', 'learned_rule_bulk_action_id', 'ai_classifier_version',
                    'ai_confidence', 'ai_outcome', 'ai_explanation', 'ai_taxonomy_fingerprint',
                    'ai_validation_context_revision', 'ai_requires_review', 'ai_reviewed_at',
                    'ai_approved_unchanged', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'categories', Category::withTrashed()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'parent_id', 'name', 'description', 'examples', 'revision',
                    'retired_at', 'deletion_id', 'purge_after', 'deleted_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'ai_category_proposals', AiCategoryProposal::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'transaction_id', 'category_assignment_id', 'parent_id', 'name',
                    'description', 'examples', 'revision', 'confirmed_category_id', 'confirmed_at',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'ai_classification_requests', AiClassificationRequest::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'transaction_id', 'expected_transaction_revision', 'attempt_count',
                    'next_attempt_at', 'queued_at', 'claimed_at', 'last_attempted_at', 'completed_at',
                    'terminal_outcome', 'last_error_code', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'ai_classification_validation_contexts', AiClassificationValidationContext::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'revision', 'classifier_version', 'taxonomy_fingerprint',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rules', LearnedRule::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'revision', 'activated_at', 'retired_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rule_revisions', LearnedRuleRevision::query()
                ->whereIn('learned_rule_id', $this->ownerIds(LearnedRule::class, $owner)), [
                    'id', 'learned_rule_id', 'revision', 'category_id', 'merchant_pattern', 'merchant_key',
                    'match_mode', 'transaction_kind', 'currency', 'payment_instrument_label',
                    'payment_instrument_last_four', 'source_category_assignment_id', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rule_suggestions', LearnedRuleSuggestion::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'category_id', 'merchant_pattern', 'merchant_key', 'match_mode',
                    'transaction_kind', 'currency', 'payment_instrument_label', 'payment_instrument_last_four',
                    'definition_hash', 'status', 'evidence_count', 'dismissed_at', 'accepted_rule_id',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rule_suggestion_evidence', LearnedRuleSuggestionEvidence::query()
                ->whereIn('learned_rule_suggestion_id', $this->ownerIds(LearnedRuleSuggestion::class, $owner)), [
                    'id', 'learned_rule_suggestion_id', 'category_assignment_id', 'transaction_id',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rule_change_previews', LearnedRuleChangePreview::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'learned_rule_id', 'expected_rule_revision',
                    'source_category_assignment_id', 'learned_rule_suggestion_id', 'definition', 'analysis',
                    'resource_fingerprint', 'expires_at', 'confirmed_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rule_bulk_actions', LearnedRuleBulkAction::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'learned_rule_id', 'learned_rule_revision', 'rules_fingerprint',
                    'status', 'preview_expires_at', 'applied_at', 'undone_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'learned_rule_bulk_action_items', LearnedRuleBulkActionItem::query()
                ->whereIn('learned_rule_bulk_action_id', $this->ownerIds(LearnedRuleBulkAction::class, $owner)), [
                    'id', 'learned_rule_bulk_action_id', 'transaction_id', 'expected_transaction_revision',
                    'previous_category_id', 'applied_transaction_revision', 'undo_transaction_revision',
                    'status', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'receipt_proposals', ReceiptProposal::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'proposal_id', 'source_kind', 'processed_at', 'provider', 'model',
                    'contract_version', 'proposed_transaction', 'proposed_line_items', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'receipt_breakdowns', ReceiptBreakdown::withTrashed()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'transaction_id', 'receipt_proposal_id', 'status', 'revision',
                    'confirmed_at', 'deletion_id', 'purge_after', 'deleted_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'line_items', LineItem::query()
                ->whereIn('receipt_breakdown_id', ReceiptBreakdown::withTrashed()
                    ->select((new ReceiptBreakdown)->qualifyColumn('id'))
                    ->whereBelongsTo($owner, 'owner')), [
                        'id', 'line_item_id', 'receipt_breakdown_id', 'category_id', 'description', 'role',
                        'quantity', 'unit_price_minor', 'related_line_item_id', 'line_total_minor',
                        'requires_review', 'created_at', 'updated_at',
                    ]);
            $this->writeDataset($stream, $digest, 'daily_exchange_rates', DailyExchangeRate::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'applicable_on', 'pen_per_usd_scaled', 'owner_managed_at', 'source',
                    'source_series', 'source_observed_on', 'source_retrieved_at', 'source_value',
                    'source_precision', 'revision', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'daily_exchange_rate_seed_requests', DailyExchangeRateSeedRequest::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'applicable_on', 'attempt_count', 'missing_observation_count',
                    'transport_failure_count', 'next_attempt_at', 'queued_at', 'claimed_at',
                    'last_attempted_at', 'completed_at', 'owner_entry_required_at', 'retrieval_failed_at',
                    'last_error_code', 'reminder_id', 'resolution_idempotency_key', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'category_targets', CategoryTarget::query()
                ->whereBelongsTo($owner, 'owner')
                ->addSelect([
                    'applicable_revision_id' => CategoryTargetRevision::query()
                        ->select('id')
                        ->whereColumn('category_target_id', (new CategoryTarget)->qualifyColumn('id'))
                        ->whereRaw(
                            'effective_month <= GREATEST(starts_on, CAST(? AS date))',
                            [now()->startOfMonth()->toDateString()],
                        )
                        ->orderByDesc('effective_month')
                        ->orderByDesc('revision')
                        ->limit(1),
                ]), [
                    'id', 'user_id', 'category_id', 'currency', 'starts_on', 'revision',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'category_target_revisions', CategoryTargetRevision::query()
                ->whereIn('category_target_id', $this->ownerIds(CategoryTarget::class, $owner)), [
                    'id', 'category_target_id', 'revision', 'effective_month', 'amount_minor',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'spending_notification_references', SpendingNotificationReference::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'transaction_id', 'spending_notification_format_id',
                    'gmail_message_discovery_id', 'parser_profile_version_id', 'gmail_account_identity',
                    'message_id', 'processing_outcome', 'attempt_count', 'last_attempted_at',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'reminders', Reminder::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'subject', 'scheduled_for', 'revision', 'acknowledged_at',
                    'snoozed_until', 'dismissed_at', 'resolved_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'reminder_deliveries', ReminderDelivery::query()
                ->whereIn('reminder_id', $this->ownerIds(Reminder::class, $owner)), [
                    'id', 'reminder_id', 'event_type', 'scheduled_for', 'occurred_at', 'attempt_count',
                    'next_attempt_at', 'queued_at', 'claimed_at', 'last_attempted_at', 'accepted_at',
                    'delivered_at', 'terminal_at', 'terminal_reason', 'last_error_code', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'reminder_lifecycle_events', ReminderLifecycleEvent::query()
                ->whereIn('reminder_id', $this->ownerIds(Reminder::class, $owner)), [
                    'id', 'reminder_id', 'service_key_id', 'schema_version', 'idempotency_key',
                    'payload_digest', 'interaction_digest', 'action', 'domain_action', 'reminder_revision',
                    'occurred_at', 'snoozed_until', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'parser_profiles', ParserProfile::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'name', 'current_version', 'enabled_at', 'security_alert_reminder_id',
                    'security_alert_resolution_idempotency_key', 'drift_alert_reminder_id',
                    'drift_alert_resolution_idempotency_key', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'parser_profile_versions', ParserProfileVersion::query()
                ->whereIn('parser_profile_id', $this->ownerIds(ParserProfile::class, $owner)), [
                    'id', 'parser_profile_id', 'version', 'trusted_sender_address', 'trusted_sender_domain',
                    'authentication_mechanism', 'authenticated_domain', 'source_gmail_account_identity',
                    'source_message_id', 'approved_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'spending_notification_formats', SpendingNotificationFormat::query()
                ->whereIn('parser_profile_version_id', ParserProfileVersion::query()->select('id')
                    ->whereIn('parser_profile_id', $this->ownerIds(ParserProfile::class, $owner))), [
                        'id', 'parser_profile_version_id', 'name', 'mime_source', 'rule_identifier',
                        'definition', 'created_at', 'updated_at',
                    ]);
            $this->writeDataset($stream, $digest, 'gmail_connections', GmailConnection::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'gmail_account_identity', 'access_token_expires_at', 'granted_scopes',
                    'connected_at', 'last_successful_check_at', 'last_check_failed_at',
                    'reauthorization_required_at', 'last_error_code', 'history_id',
                    'initial_sync_completed_at', 'last_successful_sync_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'gmail_message_discoveries', GmailMessageDiscovery::query()
                ->whereIn('gmail_connection_id', $this->ownerIds(GmailConnection::class, $owner)), [
                    'id', 'gmail_connection_id', 'message_id', 'processed_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'suspected_duplicates', SuspectedDuplicate::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'first_transaction_id', 'second_transaction_id', 'revision',
                    'survivor_transaction_id', 'voided_transaction_id', 'resolved_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'suspected_duplicate_resolutions', SuspectedDuplicateResolution::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'suspected_duplicate_id', 'idempotency_key', 'operation',
                    'survivor_transaction_id', 'expected_suspected_duplicate_revision',
                    'expected_first_transaction_revision', 'expected_second_transaction_revision',
                    'expected_first_source_reference_fingerprint', 'expected_second_source_reference_fingerprint',
                    'expected_first_receipt_breakdown_fingerprint', 'expected_second_receipt_breakdown_fingerprint',
                    'result_suspected_duplicate_revision', 'result_first_transaction_revision',
                    'result_second_transaction_revision', 'result_survivor_transaction_id',
                    'result_voided_transaction_id', 'result_resolved_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'suspected_duplicate_source_moves', SuspectedDuplicateSourceMove::query()
                ->whereIn('suspected_duplicate_resolution_id', $this->ownerIds(SuspectedDuplicateResolution::class, $owner)), [
                    'id', 'suspected_duplicate_resolution_id', 'spending_notification_reference_id',
                    'from_transaction_id', 'to_transaction_id', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'suspected_duplicate_receipt_breakdown_moves', SuspectedDuplicateReceiptBreakdownMove::query()
                ->whereIn('suspected_duplicate_resolution_id', $this->ownerIds(SuspectedDuplicateResolution::class, $owner)), [
                    'id', 'suspected_duplicate_resolution_id', 'receipt_breakdown_id', 'from_transaction_id',
                    'to_transaction_id', 'receipt_breakdown_revision', 'receipt_breakdown_status',
                    'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'integration_incidents', IntegrationIncident::query()
                ->whereBelongsTo($owner, 'owner'), [
                    'id', 'user_id', 'integration', 'work_type', 'work_id', 'source_identity', 'failure_kind',
                    'last_error_code', 'attempt_count', 'replay_count', 'first_failed_at', 'last_failed_at',
                    'visible_at', 'retry_until', 'next_attempt_at', 'parked_at', 'acknowledged_at',
                    'recovered_at', 'last_replayed_at', 'created_at', 'updated_at',
                ]);
            $this->writeDataset($stream, $digest, 'financial_data_tombstones', FinancialDataTombstone::query()
                ->where('owner_id', $owner->id), [
                    'id', 'owner_id', 'resource_type', 'resource_id', 'source_reference_type',
                    'source_reference_id', 'deleted_at', 'purged_at',
                ]);

            hash_update($digest, '}');
            $exportedAt = json_encode(now()->toIso8601String(), JSON_THROW_ON_ERROR);
            $this->write($stream, ',"exported_at":'.$exportedAt.'}');

            return new FinancialExportArtifact(
                $stream,
                hash_final($digest),
                $transactionCount,
            );
        } catch (Throwable $exception) {
            fclose($stream);

            throw $exception;
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  resource  $stream
     * @param  Builder<TModel>  $query
     * @param  list<string>  $fields
     *
     * @throws JsonException
     */
    private function writeDataset(
        mixed $stream,
        HashContext $digest,
        string $name,
        Builder $query,
        array $fields,
    ): int {
        $this->writeState($stream, $digest, ','.json_encode($name, JSON_THROW_ON_ERROR).':[');
        $count = 0;

        foreach ($query->addSelect($fields)->orderBy('id')->cursor() as $model) {
            if ($count > 0) {
                $this->writeState($stream, $digest, ',');
            }

            $this->writeState(
                $stream,
                $digest,
                json_encode($model->attributesToArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            );
            $count++;
        }

        $this->writeState($stream, $digest, ']');

        return $count;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return Builder<Model>
     */
    private function ownerIds(string $modelClass, User $owner): Builder
    {
        return $modelClass::query()
            ->select((new $modelClass)->qualifyColumn('id'))
            ->whereBelongsTo($owner, 'owner');
    }

    /** @param resource $stream */
    private function writeState(mixed $stream, HashContext $digest, string $contents): void
    {
        hash_update($digest, $contents);
        $this->write($stream, $contents);
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $contents): void
    {
        while ($contents !== '') {
            $written = fwrite($stream, $contents);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Unable to write the temporary financial export.');
            }

            $contents = substr($contents, $written);
        }
    }
}
