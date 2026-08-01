<?php

namespace App\Actions\OpenClaw;

use App\Models\OpenClawPendingOperation;
use App\Models\User;

class ReadHighImpactOperation
{
    /**
     * @return array{id: string, kind: string, effect_summary: string, expires_at: string, expected_revision: int, payload_digest: string, status: string}
     */
    public function handle(User $owner, string $operationId): array
    {
        $operation = OpenClawPendingOperation::query()
            ->whereBelongsTo($owner, 'owner')
            ->where('operation_id', $operationId)
            ->whereIn('capability', [
                'financial.export.prepare',
                'financial.deletion.prepare',
            ])
            ->firstOrFail();

        return [
            'id' => $operation->operation_id,
            'kind' => match ($operation->capability) {
                'financial.export.prepare' => 'financial_export',
                'financial.deletion.prepare' => 'financial_deletion',
                default => throw new \LogicException('Unsupported high-impact operation.'),
            },
            'effect_summary' => $operation->effect_summary,
            'expires_at' => $operation->expires_at->toIso8601String(),
            'expected_revision' => $operation->revision,
            'payload_digest' => $operation->payload_digest,
            'status' => $this->status($operation),
        ];
    }

    private function status(OpenClawPendingOperation $operation): string
    {
        if ($operation->confirmed_at !== null) {
            return 'completed';
        }

        if ($operation->canceled_at !== null) {
            return 'canceled';
        }

        if ($operation->expires_at->isPast()) {
            return 'expired';
        }

        return 'pending';
    }
}
