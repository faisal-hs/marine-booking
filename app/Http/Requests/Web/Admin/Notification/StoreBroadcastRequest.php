<?php

namespace App\Http\Requests\Web\Admin\Notification;

use App\Services\BroadcastService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('notifications.create');
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],
            'audience' => ['required', Rule::in(['customers', 'providers', 'user'])],
            // Only used for a single recipient: an e-mail address or phone number.
            'recipient' => ['required_if:audience,user', 'nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->input('audience') !== 'user' || $value === null) {
                    return;
                }

                $user = app(BroadcastService::class)->findRecipient($value);

                if ($user === null || $user->isAdmin() || (! $user->customer && ! $user->provider)) {
                    $fail(__('admin.notifications.recipient_not_found'));
                } elseif ($user->status !== 'active') {
                    $fail(__('admin.notifications.recipient_not_active'));
                }
            }],
        ];
    }

    /** The fields BroadcastService stores, with the typed recipient resolved to an account. */
    public function payload(): array
    {
        $data = $this->safe()->only(['title', 'body', 'audience']);

        $data['recipient_user_id'] = $data['audience'] === 'user'
            ? app(BroadcastService::class)->findRecipient($this->input('recipient'))?->id
            : null;

        return $data;
    }
}
