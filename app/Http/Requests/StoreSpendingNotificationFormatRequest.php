<?php

namespace App\Http\Requests;

use App\Models\ParserProfile;

class StoreSpendingNotificationFormatRequest extends StoreParserProfileRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $profile = $this->route('parser_profile');

        if ($profile instanceof ParserProfile) {
            $this->merge(['parser_profile_id' => $profile->id]);
        }
    }

    public function authorize(): bool
    {
        $profile = $this->route('parser_profile');

        return $this->user() !== null
            && $profile instanceof ParserProfile;
    }
}
