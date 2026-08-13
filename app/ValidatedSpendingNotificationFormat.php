<?php

namespace App;

use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\SpendingNotificationFormat;

final readonly class ValidatedSpendingNotificationFormat
{
    public function __construct(
        public ParserProfile $profile,
        public SpendingNotificationFormat $format,
        public GmailMessageDiscovery $discovery,
        public GmailMessage $message,
        public ?SpendingNotificationExtraction $extraction,
    ) {}
}
