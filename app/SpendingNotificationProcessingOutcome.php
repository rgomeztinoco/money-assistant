<?php

namespace App;

enum SpendingNotificationProcessingOutcome: string
{
    case AuthenticationFailed = 'authentication_failed';
    case Unsupported = 'unsupported';
    case Failed = 'failed';
    case Ignored = 'ignored';
    case Created = 'created';
    case CreatedWithReview = 'created_with_review';

    public function isRetryable(): bool
    {
        return $this === self::Unsupported;
    }
}
