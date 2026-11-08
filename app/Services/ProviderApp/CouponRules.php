<?php

namespace App\Services\ProviderApp;

use App\Models\Activity;
use App\Models\Coupon;
use App\Models\Provider;
use App\Services\CouponService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What a provider may send for a coupon. A percent coupon takes more than 0 and at most 100 off; a fixed one a positive amount. The code is chosen once
 * (letters, digits, - and _, any case, kept in capitals) and is unique among the provider's own. Its activities must be the provider's own; none means
 * all of them. The uses allowed can never be set below the uses already taken.
 */
class CouponRules
{
    public static function create(Provider $provider): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,40}$/', fn ($attribute, $value, $fail) => Coupon::where('provider_id', $provider->id)->where('code', CouponService::normalize((string) $value))->exists() ? $fail('You already have a coupon with this code.') : null],
        ] + self::shared($provider, required: true);
    }

    /** @return array<string, mixed> every field optional, the code not at all (it never changes), the status switched on or off */
    public static function update(Provider $provider): array
    {
        return ['code' => ['prohibited'], 'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])]] + self::shared($provider, required: false);
    }

    /** What must hold for the coupon AS IT WOULD BE after the change. */
    public static function after(Validator $validator, array $data, ?Coupon $existing, int $used): void
    {
        $type = $data['type'] ?? $existing?->type;
        $value = $data['value'] ?? $existing?->value;

        if ($type === Coupon::PERCENT && $value !== null && (float) $value > 100 && ! $validator->errors()->has('value')) {
            $validator->errors()->add('value', 'A percentage coupon takes at most 100 percent off.');
        }

        if (array_key_exists('usage_limit', $data) && $data['usage_limit'] !== null && (int) $data['usage_limit'] < $used && ! $validator->errors()->has('usage_limit')) {
            $validator->errors()->add('usage_limit', "The limit cannot be below the {$used} use(s) already taken.");
        }
    }

    private static function shared(Provider $provider, bool $required): array
    {
        $presence = $required ? ['required'] : ['sometimes'];

        return [
            'type' => [...$presence, 'string', Rule::in([Coupon::PERCENT, Coupon::FIXED])],
            'value' => [...$presence, 'numeric', 'gt:0'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_discount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'min_order_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'activity_ids' => ['sometimes', 'nullable', 'array'],
            'activity_ids.*' => ['integer', fn ($attribute, $value, $fail) => Activity::where('provider_id', $provider->id)->whereKey($value)->exists() ? null : $fail('This is not one of your activities.')],
        ];
    }
}
