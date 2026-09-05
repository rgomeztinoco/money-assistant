<?php

namespace App\StatementImports;

use App\MerchantNormalizer;
use App\Models\Transaction;
use App\Models\User;
use App\MovementDirection;
use App\StatementMovementClassification;
use App\StatementMovementMatchStatus;
use Carbon\CarbonImmutable;

final class StatementMovementMatcher
{
    private const DATE_PROXIMITY_DAYS = 3;

    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    public function match(User $owner, StatementImportPreview $preview): StatementImportPreview
    {
        $movements = [];
        $reservedTransactionIds = [];

        foreach ($preview->movements as $movement) {
            $match = $this->matchMovement(
                $owner,
                $preview,
                $movement,
                $reservedTransactionIds,
            );
            $movements[] = $movement->withMatch($match);

            if ($match->status === StatementMovementMatchStatus::Matched
                && $match->transactionId !== null) {
                $reservedTransactionIds[] = $match->transactionId;
            }
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

    /** @param list<int> $reservedTransactionIds */
    private function matchMovement(
        User $owner,
        StatementImportPreview $preview,
        StatementImportPreviewMovement $movement,
        array $reservedTransactionIds,
    ): StatementMovementMatch {
        if ($movement->classification === StatementMovementClassification::NotAMovement) {
            return StatementMovementMatch::fresh();
        }

        $transactionKind = $movement->classification->transactionKind();
        $transferPurpose = $movement->classification->transferPurpose();
        $isCardPayment = $movement->classification === StatementMovementClassification::CardPayment;
        $matchingDirections = $isCardPayment
            ? [MovementDirection::Debit, MovementDirection::Credit]
            : [$movement->direction];
        $dateFrom = $movement->occurredOn->subDays(self::DATE_PROXIMITY_DAYS)->toDateString();
        $dateTo = $movement->occurredOn->addDays(self::DATE_PROXIMITY_DAYS)->toDateString();
        $transactions = Transaction::query()
            ->whereBelongsTo($owner, 'owner')
            ->whereNull('voided_at')
            ->where('amount_minor', $movement->amountMinor)
            ->where('currency', $movement->currency)
            ->whereIn('direction', $matchingDirections)
            ->whereBetween('occurred_on', [$dateFrom, $dateTo])
            ->whereDoesntHave('statementMovement')
            ->when(
                $reservedTransactionIds !== [],
                fn ($query) => $query->whereNotIn('id', $reservedTransactionIds),
            )
            ->when($transactionKind !== null, fn ($query) => $query->where('kind', $transactionKind))
            ->when($transferPurpose !== null, fn ($query) => $query->where('transfer_purpose', $transferPurpose))
            ->oldest('occurred_on')
            ->oldest('id')
            ->get([
                'id',
                'occurred_on',
                'description',
                'instrument_label',
                'instrument_last_four',
                'kind',
                'direction',
                'transfer_purpose',
            ]);
        $candidates = array_values($transactions->map(function (Transaction $transaction) use ($preview, $movement, $isCardPayment): array {
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
            $directionMatches = $transaction->direction === $movement->direction;

            return [
                'id' => $transaction->id,
                'occurred_on' => $transaction->occurred_on->toDateString(),
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
                    'amount_currency' => true,
                    'direction' => $directionMatches,
                    'date_proximity' => true,
                    'instrument' => $lastFourMatches || $labelMatches,
                    'description' => $descriptionMatches,
                    'card_payment_counterpart' => $isCardPayment && ! $directionMatches,
                ],
            ];
        })->all());

        if ($candidates === []) {
            return StatementMovementMatch::fresh();
        }

        if (count($candidates) === 1
            && ($candidates[0]['evidence']['instrument']
                || $candidates[0]['evidence']['description']
                || $candidates[0]['evidence']['card_payment_counterpart'])) {
            return new StatementMovementMatch(
                status: StatementMovementMatchStatus::Matched,
                transactionId: $candidates[0]['id'],
                candidates: $candidates,
                evidence: [
                    ...$candidates[0]['evidence'],
                    'date_difference_days' => $candidates[0]['date_difference_days'],
                ],
            );
        }

        return new StatementMovementMatch(
            status: StatementMovementMatchStatus::Ambiguous,
            transactionId: null,
            candidates: $candidates,
            evidence: [],
        );
    }
}
