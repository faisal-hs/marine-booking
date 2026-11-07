<?php

namespace App\Http\Requests\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Support\ProviderApi\ErrorCode;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** The provider's reason for cancelling a confirmed booking: mandatory, because the customer is told it. */
class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    protected function failedValidation(Validator $validator): void
    {
        // A missing or empty reason has its own code, as the spec's decline reason does.
        if ($validator->errors()->has('reason')) {
            throw new ProviderApiException('A reason is required to cancel a booking.', 422, ErrorCode::CANCELLATION_REASON_REQUIRED, $validator->errors()->toArray());
        }

        parent::failedValidation($validator);
    }
}
