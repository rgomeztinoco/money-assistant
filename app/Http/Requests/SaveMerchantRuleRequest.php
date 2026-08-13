<?php

namespace App\Http\Requests;

use App\Currency;
use App\MerchantNormalizer;
use App\Models\MerchantRule;
use App\TransactionKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

class SaveMerchantRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $merchantRule = $this->route('merchant_rule');

        return $this->user() !== null
            && (! $merchantRule instanceof MerchantRule || $merchantRule->exists);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'merchant' => ['required', 'string', 'max:255'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->whereNull('archived_at'),
            ],
            'transaction_kind' => ['nullable', Rule::enum(TransactionKind::class)],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(MerchantNormalizer $merchantNormalizer): array
    {
        return [function (Validator $validator) use ($merchantNormalizer): void {
            if ($validator->errors()->hasAny(['merchant', 'transaction_kind', 'currency'])) {
                return;
            }

            try {
                $merchantKey = $merchantNormalizer->normalize($this->string('merchant')->toString());
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('merchant', $exception->getMessage());

                return;
            }

            $merchantRule = $this->route('merchant_rule');
            $conflictingScopeQuery = MerchantRule::query()
                ->where('merchant_key', $merchantKey)
                ->where('transaction_kind', $this->input('transaction_kind'))
                ->where('currency', $this->input('currency'));

            if ($merchantRule instanceof MerchantRule) {
                $conflictingScopeQuery->whereKeyNot($merchantRule->id);
            }

            if ($conflictingScopeQuery->exists()) {
                $validator->errors()->add('merchant', 'A Merchant Rule already uses this merchant, kind, and currency scope.');

                return;
            }

            if (! $this->boolean('enabled')) {
                return;
            }

            $overlappingScopeQuery = MerchantRule::query()
                ->where('merchant_key', $merchantKey)
                ->where('enabled', true)
                ->when(
                    $this->input('transaction_kind') !== null,
                    fn ($query) => $query->where(fn ($query) => $query
                        ->whereNull('transaction_kind')
                        ->orWhere('transaction_kind', $this->input('transaction_kind'))),
                )
                ->when(
                    $this->input('currency') !== null,
                    fn ($query) => $query->where(fn ($query) => $query
                        ->whereNull('currency')
                        ->orWhere('currency', $this->input('currency'))),
                );

            if ($merchantRule instanceof MerchantRule) {
                $overlappingScopeQuery->whereKeyNot($merchantRule->id);
            }

            if ($overlappingScopeQuery->exists()) {
                $validator->errors()->add('enabled', 'Disable the overlapping Merchant Rule before enabling this scope.');
            }
        }];
    }
}
