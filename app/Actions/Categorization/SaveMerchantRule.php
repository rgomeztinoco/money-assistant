<?php

namespace App\Actions\Categorization;

use App\Currency;
use App\MerchantNormalizer;
use App\Models\MerchantRule;
use App\TransactionKind;
use Illuminate\Support\Str;

final class SaveMerchantRule
{
    public function __construct(private MerchantNormalizer $merchantNormalizer) {}

    public function handle(
        string $merchant,
        int $categoryId,
        ?TransactionKind $transactionKind,
        ?Currency $currency,
        bool $enabled,
        ?MerchantRule $merchantRule = null,
    ): MerchantRule {
        $merchant = Str::squish($merchant);
        $merchantRule ??= new MerchantRule;
        $merchantRule->fill([
            'category_id' => $categoryId,
            'merchant' => $merchant,
            'merchant_key' => $this->merchantNormalizer->normalize($merchant),
            'transaction_kind' => $transactionKind,
            'currency' => $currency,
            'enabled' => $enabled,
        ])->save();

        return $merchantRule;
    }
}
