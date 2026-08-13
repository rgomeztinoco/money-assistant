<?php

namespace App\Http\Requests;

use App\Models\SpendingNotificationReference;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RetrySpendingNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $reference = $this->route('spending_notification_reference');

        return $this->user() !== null
            && $reference instanceof SpendingNotificationReference;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
