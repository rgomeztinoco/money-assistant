<?php

namespace App\Actions\NotificationIngestion;

use App\Models\ParserProfile;
use App\SpendingNotificationFormatPurpose;

final class PreviewParserProfile
{
    public function __construct(
        private ValidateSpendingNotificationFormat $validateFormat,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     purpose: 'spending',
     *     occurred_on: string,
     *     amount_minor: string,
     *     currency: string,
     *     kind: string,
     *     merchant_description: string,
     *     provisional_fields: list<string>
     * }|array{purpose: 'ignore'}
     */
    public function handle(array $attributes): array
    {
        $profile = isset($attributes['parser_profile_id'])
            ? ParserProfile::query()
                ->whereKey($attributes['parser_profile_id'])
                ->sole()
            : null;
        $validatedFormat = $this->validateFormat->handle($attributes, $profile);
        $extraction = $validatedFormat->extraction;

        if ($extraction === null) {
            return ['purpose' => SpendingNotificationFormatPurpose::Ignore->value];
        }

        return [
            'purpose' => SpendingNotificationFormatPurpose::Spending->value,
            'occurred_on' => $extraction->occurredOn->toDateString(),
            'amount_minor' => (string) $extraction->amountMinor,
            'currency' => $extraction->currency->value,
            'kind' => $extraction->kind->value,
            'merchant_description' => $extraction->merchantDescription,
            'provisional_fields' => array_map(
                static fn ($field): string => $field->value,
                $extraction->provisionalFields,
            ),
        ];
    }
}
