<?php

namespace App\Http\Requests\ProviderApp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * How the provider wants to be told things. Any of the four may be sent, each true or false (a form sends the words "true" and "false", or 1 and 0), and at least one must be.
 * A preference that is not sent is left as it is; null is not a preference.
 */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    /** What the app calls each, and the column it is kept in. */
    public const COLUMNS = ['email' => 'notify_email', 'sms' => 'notify_sms', 'whatsapp' => 'notify_whatsapp', 'news_and_offers' => 'notify_news_and_offers'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // A form sends the words, and only these are understood: "true", "false", "1" and "0" (not "yes" or "on": the rule below refuses those).
        $words = ['true' => true, 'false' => false, '1' => true, '0' => false];

        foreach (array_keys(self::COLUMNS) as $key) {
            if ($this->has($key) && is_string($this->input($key)) && array_key_exists($word = strtolower(trim($this->input($key))), $words)) {
                $this->merge([$key => $words[$word]]);
            }
        }
    }

    public function rules(): array
    {
        return array_fill_keys(array_keys(self::COLUMNS), ['sometimes', 'boolean']);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->any() && ! $this->hasAny(array_keys(self::COLUMNS))) {
                $validator->errors()->add('body', 'Send at least one of: '.implode(', ', array_keys(self::COLUMNS)).'.');
            }
        }];
    }

    /** @return array<string, bool> the columns to change, with their new values */
    public function changes(): array
    {
        $changes = [];

        foreach (self::COLUMNS as $key => $column) {
            if ($this->has($key)) {
                $changes[$column] = $this->boolean($key);
            }
        }

        return $changes;
    }
}
