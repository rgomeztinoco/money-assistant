<?php

namespace App;

final class SourceReferenceSetFingerprint
{
    /**
     * @param  iterable<int>  $sourceReferenceIds
     */
    public static function fromIds(iterable $sourceReferenceIds): string
    {
        $normalizedIds = [];

        foreach ($sourceReferenceIds as $sourceReferenceId) {
            $normalizedIds[] = $sourceReferenceId;
        }

        sort($normalizedIds, SORT_NUMERIC);

        return hash_hmac(
            'sha256',
            implode(',', $normalizedIds),
            (string) config('app.key'),
        );
    }
}
