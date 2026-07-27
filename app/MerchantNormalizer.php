<?php

namespace App;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Normalizer;

final class MerchantNormalizer
{
    public function normalize(string $merchant): string
    {
        $unicodeNormalized = Normalizer::normalize($merchant, Normalizer::FORM_KC);

        if ($unicodeNormalized === false) {
            throw new InvalidArgumentException('The merchant pattern must contain valid Unicode text.');
        }

        $punctuationStandardized = preg_replace('/\p{P}+/u', ' ', Str::lower($unicodeNormalized));
        $merchantKey = Str::squish($punctuationStandardized ?? '');

        if ($merchantKey === '') {
            throw new InvalidArgumentException('The merchant pattern must contain searchable text.');
        }

        return $merchantKey;
    }
}
