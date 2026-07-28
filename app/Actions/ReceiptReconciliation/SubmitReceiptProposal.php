<?php

namespace App\Actions\ReceiptReconciliation;

use App\Exceptions\IdempotencyKeyConflict;
use App\Models\OpenClawAuditEvent;
use App\Models\ReceiptProposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SubmitReceiptProposal
{
    /**
     * @param  array{occurred_on: string, amount_minor: int, currency: string, kind: string, merchant_description: string}  $proposedTransaction
     * @param  list<array{description: string, role?: string, quantity?: string|null, unit_price_minor?: int|null, line_total_minor: int}>  $proposedLineItems
     * @return array{proposal: ReceiptProposal, replayed: bool}
     */
    public function handle(
        User $owner,
        string $serviceKeyId,
        int $schemaVersion,
        string $nonceDigest,
        string $requestDigest,
        string $interactionDigest,
        string $proposalId,
        string $sourceKind,
        CarbonImmutable $processedAt,
        string $provider,
        string $model,
        int $contractVersion,
        array $proposedTransaction,
        array $proposedLineItems,
    ): array {
        $operationDigest = hash('sha256', $this->canonicalJson([
            'proposal_id' => $proposalId,
            'source_kind' => $sourceKind,
            'processed_at' => $processedAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'provider' => $provider,
            'model' => $model,
            'contract_version' => $contractVersion,
            'transaction' => $proposedTransaction,
            'line_items' => $proposedLineItems,
        ]));

        return DB::transaction(function () use (
            $owner,
            $serviceKeyId,
            $schemaVersion,
            $nonceDigest,
            $requestDigest,
            $interactionDigest,
            $proposalId,
            $sourceKind,
            $processedAt,
            $provider,
            $model,
            $contractVersion,
            $proposedTransaction,
            $proposedLineItems,
            $operationDigest,
        ): array {
            User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $existingSubmission = OpenClawAuditEvent::query()
                ->where('event_kind', 'proposal')
                ->where('service_key_id', $serviceKeyId)
                ->where('schema_version', $schemaVersion)
                ->where('capability', 'receipt.proposal.submit')
                ->where('idempotency_key', $proposalId)
                ->first();

            if ($existingSubmission !== null) {
                if (! is_string($existingSubmission->operation_digest)
                    || ! hash_equals($existingSubmission->operation_digest, $operationDigest)) {
                    throw new IdempotencyKeyConflict;
                }

                $proposal = ReceiptProposal::query()
                    ->whereBelongsTo($owner, 'owner')
                    ->findOrFail($existingSubmission->resource_id);

                $this->recordRequestAudit(
                    $serviceKeyId,
                    $schemaVersion,
                    'idempotent_replay',
                    $nonceDigest,
                    $requestDigest,
                    $interactionDigest,
                );

                return ['proposal' => $proposal, 'replayed' => true];
            }

            $existing = ReceiptProposal::query()
                ->whereBelongsTo($owner, 'owner')
                ->where('proposal_id', $proposalId)
                ->first();

            if ($existing !== null) {
                if ($existing->source_kind !== $sourceKind
                    || ! $existing->processed_at->equalTo($processedAt)
                    || $existing->provider !== $provider
                    || $existing->model !== $model
                    || $existing->contract_version !== $contractVersion
                    || ! hash_equals(
                        $this->canonicalJson($existing->proposed_transaction),
                        $this->canonicalJson($proposedTransaction),
                    )
                    || ! hash_equals(
                        $this->canonicalJson($existing->proposed_line_items),
                        $this->canonicalJson($proposedLineItems),
                    )) {
                    throw new IdempotencyKeyConflict;
                }

                $this->recordProposalAudit(
                    $serviceKeyId,
                    $schemaVersion,
                    $nonceDigest,
                    $requestDigest,
                    $interactionDigest,
                    $proposalId,
                    $operationDigest,
                    $existing,
                );

                return ['proposal' => $existing, 'replayed' => true];
            }

            $proposal = ReceiptProposal::query()->create([
                'user_id' => $owner->getKey(),
                'proposal_id' => $proposalId,
                'source_kind' => $sourceKind,
                'processed_at' => $processedAt,
                'provider' => $provider,
                'model' => $model,
                'contract_version' => $contractVersion,
                'proposed_transaction' => $proposedTransaction,
                'proposed_line_items' => $proposedLineItems,
            ]);

            $this->recordProposalAudit(
                $serviceKeyId,
                $schemaVersion,
                $nonceDigest,
                $requestDigest,
                $interactionDigest,
                $proposalId,
                $operationDigest,
                $proposal,
            );

            return ['proposal' => $proposal, 'replayed' => false];
        }, 3);
    }

    private function recordRequestAudit(
        string $serviceKeyId,
        int $schemaVersion,
        string $outcome,
        string $nonceDigest,
        string $requestDigest,
        string $interactionDigest,
    ): void {
        OpenClawAuditEvent::query()->create([
            'occurred_at' => now(),
            'service_key_id' => $serviceKeyId,
            'schema_version' => $schemaVersion,
            'capability' => 'receipt.proposal.submit',
            'outcome' => $outcome,
            'http_status' => 200,
            'nonce_digest' => $nonceDigest,
            'request_digest' => $requestDigest,
            'interaction_digest' => $interactionDigest,
            'resource_type' => 'receipt_proposal',
            'result_count' => 1,
        ]);
    }

    private function recordProposalAudit(
        string $serviceKeyId,
        int $schemaVersion,
        string $nonceDigest,
        string $requestDigest,
        string $interactionDigest,
        string $proposalId,
        string $operationDigest,
        ReceiptProposal $proposal,
    ): void {
        OpenClawAuditEvent::query()->create([
            'occurred_at' => now(),
            'service_key_id' => $serviceKeyId,
            'schema_version' => $schemaVersion,
            'capability' => 'receipt.proposal.submit',
            'outcome' => 'success',
            'http_status' => 200,
            'nonce_digest' => $nonceDigest,
            'request_digest' => $requestDigest,
            'interaction_digest' => $interactionDigest,
            'resource_type' => 'receipt_proposal',
            'result_count' => 1,
            'event_kind' => 'proposal',
            'idempotency_key' => $proposalId,
            'operation_digest' => $operationDigest,
            'domain_action' => 'receipt_proposal.submit',
            'resource_id' => $proposal->id,
            'resource_revision' => 1,
        ]);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalize(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = is_array($item) ? $this->canonicalize($item) : $item;
        }

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
