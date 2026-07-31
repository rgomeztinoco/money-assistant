<?php

namespace App;

use App\Integrations\Gmail\GmailMessage;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use App\Models\ParserProfileVersion;
use App\Models\SpendingNotificationFormat;

final readonly class ParserProfileProposal
{
    public function __construct(
        public ?ParserProfile $existingProfile,
        public string $profileName,
        public GmailMessageDiscovery $discovery,
        public GmailMessage $message,
        public ParserProfileVersion $profileVersion,
        public SpendingNotificationFormat $format,
        public ?SpendingNotificationExtraction $extraction,
    ) {}
}
