<?php

namespace App\Http\Requests;

use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\ParserProfile;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParserProfileRequest extends FormRequest
{
    /**
     * Normalize identifiers submitted by HTML forms without accepting malformed values.
     */
    protected function prepareForValidation(): void
    {
        $identifiers = [];
        $sourceMessageDiscoveryId = $this->input('source_message_discovery_id');
        $parserProfileId = $this->input('parser_profile_id');

        if (is_string($sourceMessageDiscoveryId) && ctype_digit($sourceMessageDiscoveryId)) {
            $identifiers['source_message_discovery_id'] = (int) $sourceMessageDiscoveryId;
        }

        if (is_string($parserProfileId) && ctype_digit($parserProfileId)) {
            $identifiers['parser_profile_id'] = (int) $parserProfileId;
        }

        $this->merge($identifiers);
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $ownerId = (int) $this->user()->getKey();
        $ownedGmailConnectionIds = GmailConnection::query()
            ->where('user_id', $ownerId)
            ->select('id');

        return [
            'source_message_discovery_id' => [
                'required',
                'integer',
                Rule::exists(GmailMessageDiscovery::class, 'id')
                    ->where(
                        fn (Builder $query): Builder => $query->whereIn(
                            'gmail_connection_id',
                            $ownedGmailConnectionIds,
                        ),
                    ),
            ],
            'parser_profile_id' => [
                'nullable',
                'integer',
                Rule::exists(ParserProfile::class, 'id')
                    ->where(
                        fn (Builder $query): Builder => $query->where(
                            'user_id',
                            $ownerId,
                        ),
                    ),
            ],
            'profile_name' => ['nullable', 'required_without:parser_profile_id', 'string', 'max:255'],
            'format_name' => ['required', 'string', 'max:255'],
            'authentication_mechanism' => ['required', Rule::in(['spf', 'dkim', 'dmarc'])],
            'mime_source' => ['required', Rule::in(['text_plain', 'text_html'])],
            'subject_marker' => ['required', 'string', 'max:255'],
            'body_marker' => ['required', 'string', 'max:500'],
            'amount_prefix' => ['required', 'string', 'max:255'],
            'amount_suffix' => ['required', 'string', 'max:255'],
            'decimal_separator' => ['required', Rule::in(['.', ','])],
            'grouping_separator' => ['required', Rule::in(['none', '.', ',', 'space'])],
            'currency_position' => ['required', Rule::in(['before', 'after'])],
            'currency_token' => ['required', 'string', 'max:20'],
            'currency' => ['required', Rule::in(['USD', 'PEN'])],
            'date_prefix' => ['required', 'string', 'max:255'],
            'date_suffix' => ['required', 'string', 'max:255'],
            'date_format' => [
                'required',
                Rule::in([
                    'd/m/Y',
                    'd-m-Y',
                    'Y-m-d',
                    'd/m/Y H:i',
                    'd-m-Y H:i',
                    'Y-m-d H:i',
                ]),
            ],
            'timezone' => ['required', 'timezone:all'],
            'amount_semantics' => ['required', Rule::in(['absolute', 'signed'])],
            'kind_semantics' => ['required', Rule::in(['fixed_purchase', 'fixed_refund'])],
            'merchant_prefix' => ['nullable', 'required_with:merchant_suffix', 'string', 'max:255'],
            'merchant_suffix' => ['nullable', 'required_with:merchant_prefix', 'string', 'max:255'],
        ];
    }
}
