<?php

namespace App\Http\Requests;

use App\Currency;
use App\MerchantNormalizer;
use App\Models\MerchantRule;
use App\Models\Transaction;
use App\TransactionKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class CategorizeReviewTransactionRequest extends FormRequest
{
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
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('user_id', $this->user()->getKey())
                    ->whereNull('archived_at'),
            ],
            'create_merchant_rule' => ['required', 'boolean'],
            'bulk_assign' => ['required', 'boolean'],
            'rule_transaction_kind' => ['nullable', Rule::in([
                TransactionKind::Spending->value,
                TransactionKind::Refund->value,
            ])],
            'rule_currency' => ['nullable', Rule::enum(Currency::class)],
            'next_review_item' => ['nullable', 'string', 'regex:/^(transaction|line-item):[1-9][0-9]*$/'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(MerchantNormalizer $merchantNormalizer): array
    {
        return [function (Validator $validator) use ($merchantNormalizer): void {
            if ($validator->errors()->isNotEmpty()
                || (! $this->boolean('create_merchant_rule') && ! $this->boolean('bulk_assign'))) {
                return;
            }

            $transaction = $this->route('transaction');

            if (! $transaction instanceof Transaction) {
                return;
            }

            try {
                $merchantKey = $merchantNormalizer->normalize($transaction->merchant_description);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('merchant_context', $exception->getMessage());

                return;
            }

            if (! $this->boolean('create_merchant_rule')) {
                return;
            }

            $merchantRules = MerchantRule::query()
                ->whereBelongsTo($this->user(), 'owner')
                ->where('merchant_key', $merchantKey);
            $exactScopeExists = (clone $merchantRules)
                ->where('transaction_kind', $this->input('rule_transaction_kind'))
                ->where('currency', $this->input('rule_currency'))
                ->exists();

            if ($exactScopeExists) {
                $validator->errors()->add(
                    'create_merchant_rule',
                    'A Merchant Rule already uses this merchant, kind, and currency scope.',
                );

                return;
            }

            $overlappingRuleExists = $merchantRules
                ->where('enabled', true)
                ->when(
                    $this->input('rule_transaction_kind') !== null,
                    fn ($query) => $query->where(fn ($query) => $query
                        ->whereNull('transaction_kind')
                        ->orWhere('transaction_kind', $this->input('rule_transaction_kind'))),
                )
                ->when(
                    $this->input('rule_currency') !== null,
                    fn ($query) => $query->where(fn ($query) => $query
                        ->whereNull('currency')
                        ->orWhere('currency', $this->input('rule_currency'))),
                )
                ->exists();

            if ($overlappingRuleExists) {
                $validator->errors()->add(
                    'create_merchant_rule',
                    'An enabled Merchant Rule already covers this merchant and scope.',
                );
            }
        }];
    }
}
