<?php

namespace App\Http\Requests;

use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;

class UpdateSpendingNotificationFormatRequest extends StoreParserProfileRequest
{
    public function authorize(): bool
    {
        $profile = $this->route('parser_profile');
        $format = $this->route('spending_notification_format');

        return $this->user() !== null
            && $profile instanceof ParserProfile
            && $format instanceof SpendingNotificationFormat
            && $format->parser_profile_id === $profile->id;
    }
}
