<?php

namespace App\Actions\NotificationIngestion;

use App\Models\User;
use App\SpendingNotificationFormatPurpose;

final class PreviewParserProfile
{
    public function __construct(
        private BuildParserProfileProposal $buildProposal,
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
    public function handle(User $owner, array $attributes): array
    {
        $proposal = $this->buildProposal->handle($owner, $attributes);
        $extraction = $proposal->extraction;

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
