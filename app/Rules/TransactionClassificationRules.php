<?php

namespace App\Rules;

use App\IncomeSource;
use App\TransactionKind;
use App\TransferPurpose;
use Illuminate\Validation\Rule;

final class TransactionClassificationRules
{
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
}
