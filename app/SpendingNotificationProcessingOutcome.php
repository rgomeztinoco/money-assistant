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

    /** @return list<string> */
    public static function failureValues(): array
    {
        return array_map(
            static fn (self $outcome): string => $outcome->value,
            [self::AuthenticationFailed, self::Unsupported, self::Failed],
        );
    }

    /** @return list<string> */
    public static function successValues(): array
    {
        return array_map(
            static fn (self $outcome): string => $outcome->value,
            [self::Created, self::CreatedWithReview],
        );
    }
}
