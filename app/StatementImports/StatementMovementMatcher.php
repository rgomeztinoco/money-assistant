<?php

namespace App\StatementImports;

use App\MerchantNormalizer;
use App\Models\Transaction;
use App\Models\User;
use App\StatementMovementClassification;
use App\StatementMovementMatchStatus;
use App\StatementMovementReviewReason;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class StatementMovementMatcher
{
    public const DATE_PROXIMITY_DAYS = 3;

    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    public function match(User $owner, StatementImportPreview $preview): StatementImportPreview
    {
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [
                $preview->periodStart->subDays(self::DATE_PROXIMITY_DAYS)->toDateString(),
                $preview->periodEnd->addDays(self::DATE_PROXIMITY_DAYS)->toDateString(),
            ])
            ->whereDoesntHave('statementMovement')
            ->oldest('occurred_on')
            ->oldest('id')
            ->get([
                'id',
                'occurred_on',
                'amount_minor',
                'currency',
                'direction',
                'description',
                'instrument_label',
                'instrument_last_four',
                'kind',
                'transfer_purpose',
            ]);
        $matchedMovements = [];

        foreach ($preview->movements as $movement) {
            $matchedMovements[] = $movement->withMatch(
                $this->matchMovement($preview, $movement, $transactions),
            );
        }
        $candidateClaimCounts = [];

        foreach ($matchedMovements as $movement) {
            foreach ($movement->match->candidates as $candidate) {
                if (! $candidate['evidence']['plausible']) {
                    continue;
                }

                $candidateClaimCounts[$candidate['id']] = ($candidateClaimCounts[$candidate['id']] ?? 0) + 1;
            }
        }

        $movements = [];

        foreach ($matchedMovements as $movement) {
            $match = $movement->match ?? StatementMovementMatch::fresh();
            $hasCollision = collect($match->candidates)->contains(
                fn (array $candidate): bool => $candidate['evidence']['plausible']
                    && ($candidateClaimCounts[$candidate['id']] ?? 0) > 1,
            );

            if (! $hasCollision) {
                $movements[] = $movement;

                continue;
            }

            $movements[] = $movement->withMatch(new StatementMovementMatch(
                status: StatementMovementMatchStatus::Ambiguous,
                transactionId: null,
                candidates: $match->candidates,
                evidence: [],
                reviewReason: StatementMovementReviewReason::MultipleMatches,
            ));
        }

        return new StatementImportPreview(
            financialStatementFormat: $preview->financialStatementFormat,
            parserVersion: $preview->parserVersion,
            fileHash: $preview->fileHash,
            periodStart: $preview->periodStart,
            periodEnd: $preview->periodEnd,
            instrumentLabel: $preview->instrumentLabel,
            instrumentLastFour: $preview->instrumentLastFour,
            movements: $movements,
            informationalValues: $preview->informationalValues,
            reconciliation: $preview->reconciliation,
        );
    }

    /** @param Collection<int, Transaction> $transactions */
    private function matchMovement(
        StatementImportPreview $preview,
        StatementImportPreviewMovement $movement,
        Collection $transactions,
    ): StatementMovementMatch {
        if ($movement->classification === StatementMovementClassification::NotAMovement) {
            return StatementMovementMatch::fresh();
        }

        $transactionKind = $movement->classification->transactionKind();
        $transferPurpose = $movement->classification->transferPurpose();
        $isCardPayment = $movement->classification === StatementMovementClassification::CardPayment;
        $candidates = $transactions->map(function (Transaction $transaction) use ($preview, $movement, $isCardPayment, $transactionKind, $transferPurpose): ?array {
            $descriptionMatches = $this->merchantNormalizer->normalize($transaction->description)
                === $this->merchantNormalizer->normalize($movement->description);
            $lastFourMatches = $preview->instrumentLastFour !== null
                && $transaction->instrument_last_four !== null
                && hash_equals($preview->instrumentLastFour, $transaction->instrument_last_four);
            $labelMatches = $transaction->instrument_label !== null
                && $this->merchantNormalizer->normalize($transaction->instrument_label)
                    === $this->merchantNormalizer->normalize($preview->instrumentLabel);
            $dateDifference = $movement->occurredOn->diffInDays(
                CarbonImmutable::parse($transaction->occurred_on),
            );
            $amountMatches = (string) $transaction->amount_minor === $movement->amountMinor;
            $currencyMatches = $transaction->currency === $movement->currency;
            $directionMatches = $transaction->direction === $movement->direction;
            $kindMatches = $transactionKind === null || $transaction->kind === $transactionKind;
            $transferPurposeMatches = $transferPurpose === null
                || $transaction->transfer_purpose === $transferPurpose;
            $dateMatches = $dateDifference <= self::DATE_PROXIMITY_DAYS;
            $isPlausible = $amountMatches
                && $currencyMatches
                && ($directionMatches || $isCardPayment)
                && $dateMatches
                && $kindMatches
                && $transferPurposeMatches;
            $conflictingFieldCount = collect([
                $amountMatches,
                $currencyMatches,
                $directionMatches || $isCardPayment,
                $kindMatches && $transferPurposeMatches,
            ])->filter(fn (bool $matches): bool => ! $matches)->count();
            $hasIdentityConflict = $dateMatches
                && $descriptionMatches
                && ($lastFourMatches || $labelMatches)
                && $conflictingFieldCount > 0;

            if (! $isPlausible && ! $hasIdentityConflict) {
                return null;
            }

            return [
                'id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'amount_minor' => (string) $transaction->amount_minor,
                'currency' => $transaction->currency->value,
                'direction' => $transaction->direction->value,
                'description' => $transaction->description,
                'instrument_label' => $transaction->instrument_label,
                'instrument_last_four' => $transaction->instrument_last_four,
                'kind' => $transaction->kind->value,
                'transfer_purpose' => $transaction->transfer_purpose?->value,
                'compatible_classifications' => array_values(array_map(
                    fn (StatementMovementClassification $classification): string => $classification->value,
                    array_filter(
                        StatementMovementClassification::cases(),
                        fn (StatementMovementClassification $classification): bool => $classification->isCompatibleWith(
                            $transaction->kind,
                            $transaction->transfer_purpose,
                        ),
                    ),
                )),
                'date_difference_days' => (int) $dateDifference,
                'evidence' => [
                    'amount' => $amountMatches,
                    'currency' => $currencyMatches,
                    'direction' => $directionMatches,
                    'date_proximity' => $dateMatches,
                    'instrument' => $lastFourMatches || $labelMatches,
                    'description' => $descriptionMatches,
                    'kind' => $kindMatches,
                    'transfer_purpose' => $transferPurposeMatches,
                    'card_payment_counterpart' => $isCardPayment && ! $directionMatches,
                    'plausible' => $isPlausible,
                ],
            ];
        })->filter()->values()->all();

        if ($candidates === []) {
            return $transactionKind === null
                ? new StatementMovementMatch(
                    status: StatementMovementMatchStatus::Ambiguous,
                    transactionId: null,
                    candidates: [],
                    evidence: [],
                    reviewReason: StatementMovementReviewReason::LowConfidence,
                )
                : StatementMovementMatch::fresh();
        }

        $plausibleCandidates = collect($candidates)
            ->filter(fn (array $candidate): bool => $candidate['evidence']['plausible'])
            ->values()
            ->all();
        $conflictingCandidates = collect($candidates)
            ->reject(fn (array $candidate): bool => $candidate['evidence']['plausible'])
            ->values()
            ->all();

        if ($conflictingCandidates !== []) {
            return new StatementMovementMatch(
                status: StatementMovementMatchStatus::Ambiguous,
                transactionId: null,
                candidates: $candidates,
                evidence: [],
                reviewReason: StatementMovementReviewReason::ConflictingData,
            );
        }

        if ($transactionKind === null) {
            return new StatementMovementMatch(
                status: StatementMovementMatchStatus::Ambiguous,
                transactionId: null,
                candidates: $plausibleCandidates,
                evidence: [],
                reviewReason: StatementMovementReviewReason::LowConfidence,
            );
        }

        if (count($plausibleCandidates) === 1
            && ($plausibleCandidates[0]['evidence']['instrument']
                || $plausibleCandidates[0]['evidence']['description']
                || $plausibleCandidates[0]['evidence']['card_payment_counterpart'])) {
            return new StatementMovementMatch(
                status: StatementMovementMatchStatus::Matched,
                transactionId: $plausibleCandidates[0]['id'],
                candidates: $plausibleCandidates,
                evidence: [
                    ...$plausibleCandidates[0]['evidence'],
                    'date_difference_days' => $plausibleCandidates[0]['date_difference_days'],
                ],
            );
        }

        return new StatementMovementMatch(
            status: StatementMovementMatchStatus::Ambiguous,
            transactionId: null,
            candidates: $plausibleCandidates,
            evidence: [],
            reviewReason: count($plausibleCandidates) > 1
                ? StatementMovementReviewReason::MultipleMatches
                : StatementMovementReviewReason::LowConfidence,
        );
    }
}
