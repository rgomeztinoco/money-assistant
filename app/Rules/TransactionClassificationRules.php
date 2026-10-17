<?php

namespace App\Rules;

use App\IncomeSource;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Validation\Rule;

final class TransactionClassificationRules
{
    /** @return list<TransactionKind> */
    public static function agentKinds(): array
    {
        return [TransactionKind::Spending, TransactionKind::Refund, TransactionKind::Income, TransactionKind::Transfer];
    }

    /** @return array<string, array<mixed>> */
    public static function fields(mixed $kind): array
    {
        return [
            'kind' => ['required', Rule::enum(TransactionKind::class)],
            'income_source' => [
                Rule::requiredIf($kind === TransactionKind::Income->value),
                'nullable', Rule::enum(IncomeSource::class),
            ],
            'transfer_purpose' => [
                Rule::requiredIf($kind === TransactionKind::Transfer->value),
                'nullable', Rule::enum(TransferPurpose::class),
            ],
        ];
    }

    /** @return array<string, array<mixed>> */
    public static function agentFields(mixed $kind): array
    {
        $rules = [
            ...self::fields($kind),
            'kind' => ['required', Rule::enum(TransactionKind::class)->only(self::agentKinds())],
            'original_spending_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
        foreach ([
            TransactionKind::Refund->value => 'original_spending_id',
            TransactionKind::Income->value => 'income_source',
            TransactionKind::Transfer->value => 'transfer_purpose',
        ] as $applicableKind => $field) {
            if ($kind !== $applicableKind) {
                $rules[$field] = ['missing'];
            }
        }

        return $rules;
    }
}
