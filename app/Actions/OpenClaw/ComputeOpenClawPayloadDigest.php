<?php

namespace App\Actions\OpenClaw;

use JsonException;

final class ComputeOpenClawPayloadDigest
{
    /**
     * @param  array<mixed>  $payload
     *
     * @throws JsonException
     */
    public function handle(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    /**
     * @param  array<mixed>  $value
     *
     * @throws JsonException
     */
    private function canonicalJson(array $value): string
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
