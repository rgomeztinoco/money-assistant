<?php

namespace App\Http\Requests;

use App\Models\Transaction;
use App\ReviewableTransactionField;
use App\TransactionFieldResolution;
use App\TransactionKind;
use App\TransferPurpose;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveTransactionFieldRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $transaction = $this->route('transaction');

        return $this->user() !== null
            && $transaction instanceof Transaction
            && $transaction->user_id === $this->user()->getKey();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $field = $this->route('field');
        $field = $field instanceof ReviewableTransactionField
            ? $field
            : ReviewableTransactionField::tryFrom((string) $field);
        $transaction = $this->route('transaction');

        return [
            'resolution' => ['required', Rule::enum(TransactionFieldResolution::class)],
            'value' => [
                'exclude_unless:resolution,'.TransactionFieldResolution::Correct->value,
                'required',
                ...$this->replacementValueRules(
                    $field,
                    $transaction instanceof Transaction ? $transaction : null,
                ),
            ],
            'transfer_purpose' => [
                Rule::requiredIf(
                    $field === ReviewableTransactionField::Kind
                    && $this->input('resolution') === TransactionFieldResolution::Correct->value
                    && $this->input('value') === TransactionKind::Transfer->value,
                ),
                'nullable',
                Rule::enum(TransferPurpose::class),
            ],
            'next_review_item' => ['nullable', 'string', 'regex:/^(transaction|line-item):[1-9][0-9]*$/'],
        ];
    }

    /**
     * @return list<ValidationRule|Closure|string>
     */
    private function replacementValueRules(
        ?ReviewableTransactionField $field,
        ?Transaction $transaction,
    ): array {
        return match ($field) {
            ReviewableTransactionField::OccurredOn,
            ReviewableTransactionField::AmountMinor,
            ReviewableTransactionField::Currency,
            ReviewableTransactionField::Kind,
            ReviewableTransactionField::Description => [
                function (string $attribute, mixed $value, Closure $fail) use ($field, $transaction): void {
                    if ($transaction === null) {
                        $fail('The reviewed Transaction is unavailable.');

                        return;
                    }

                    try {
                        $field->normalizeReplacement($value, $transaction);
                    } catch (\InvalidArgumentException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
            null => ['prohibited'],
        };
    }
}
