<?php

namespace App\Http\Requests\Provider\Activity\Concerns;

/**
 * `allowed_payment_methods: {tabby: true, tamara: false}` on creating or changing an activity. Either key can be left out, or null (it is then left as it is, and a
 * new activity starts with both off). A form upload sends "true" and "false" as words, so they are turned into booleans before they are checked.
 */
trait HasPaymentMethods
{
    protected function normalisePaymentMethods(): void
    {
        $methods = $this->input('allowed_payment_methods');

        if (is_array($methods)) {
            $this->merge(['allowed_payment_methods' => collect($methods)->map(fn ($v) => is_string($v) ? (filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $v) : $v)->all()]);
        }
    }

    /** @return array<string, list<string>> */
    protected function paymentMethodRules(): array
    {
        return [
            // Only these two keys: an unknown method is refused, not quietly ignored.
            'allowed_payment_methods' => ['sometimes', 'nullable', 'array:tabby,tamara'],
            'allowed_payment_methods.tabby' => ['sometimes', 'nullable', 'boolean'],
            'allowed_payment_methods.tamara' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    /** @return array<string, bool> the columns to set: only for the methods that were sent */
    protected function paymentMethodPayload(): array
    {
        $methods = (array) $this->input('allowed_payment_methods', []);

        return collect(['tabby', 'tamara'])->filter(fn ($m) => isset($methods[$m]))->mapWithKeys(fn ($m) => ["allows_{$m}" => (bool) $methods[$m]])->all();
    }
}
