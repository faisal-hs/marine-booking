<?php

namespace App\Http\Controllers\Api\ProviderApp;

use App\Exceptions\ProviderApiException;
use App\Http\Requests\ProviderApp\ListCouponsRequest;
use App\Models\Coupon;
use App\Services\CouponService;
use App\Services\ProviderApp\CouponRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The provider's own coupons. The provider funds the discount; there are no platform-funded coupons. A coupon is switched off, never deleted, so the
 * bookings that used it keep their history. Another provider's coupon is "not found".
 */
class CouponController extends ProviderAppController
{
    public function __construct(private readonly CouponService $coupons) {}

    public function index(ListCouponsRequest $request)
    {
        $query = $this->coupons->withUsedCount(Coupon::query()->where('provider_id', $this->provider($request)->id)->with('activities:id'));

        $query->when($request->input('status'), fn ($q, $status) => match ($status) {
            'inactive' => $q->where('is_active', false),
            'expired' => $q->where('is_active', true)->where('expires_at', '<=', now()),
            default => $q->where('is_active', true)->where(fn ($a) => $a->whereNull('expires_at')->orWhere('expires_at', '>', now())),
        });

        $page = $query->orderByDesc('id')->paginate($request->perPage(), ['*'], 'page', $request->page());

        return $this->page($page, collect($page->items())->map(fn (Coupon $c) => $this->describe($c, (int) $c->used_count))->all());
    }

    public function store(Request $request)
    {
        $provider = $this->provider($request);
        $data = $this->validated($request, CouponRules::create($provider), null, 0);

        $coupon = DB::transaction(function () use ($provider, $data) {
            $coupon = Coupon::create($this->columns($data) + ['provider_id' => $provider->id, 'code' => CouponService::normalize($data['code']), 'is_active' => true]);
            $coupon->activities()->sync(array_unique($data['activity_ids'] ?? []));

            return $coupon;
        });

        return $this->created($this->describe($coupon->load('activities:id'), 0), 'Coupon created');
    }

    public function update(Request $request, string $id)
    {
        $coupon = $this->own($request, $id);
        $used = $this->coupons->usedCount($coupon);
        $data = $this->validated($request, CouponRules::update($this->provider($request)), $coupon, $used);

        DB::transaction(function () use ($coupon, $data) {
            $coupon->update($this->columns($data) + (isset($data['status']) ? ['is_active' => $data['status'] === 'active'] : []));

            if (array_key_exists('activity_ids', $data)) {
                $coupon->activities()->sync(array_unique($data['activity_ids'] ?? []));
            }
        });

        return $this->ok($this->describe($coupon->refresh()->load('activities:id'), $used), 'Coupon updated');
    }

    /** Stops the coupon being used on future bookings. Nothing is deleted. */
    public function destroy(Request $request, string $id)
    {
        $coupon = $this->own($request, $id);
        $coupon->update(['is_active' => false]);

        return $this->ok(['id' => $coupon->id, 'status' => 'inactive'], 'Coupon deactivated');
    }

    private function own(Request $request, string $id): Coupon
    {
        $coupon = ctype_digit($id) ? Coupon::where('provider_id', $this->provider($request)->id)->find((int) $id) : null;

        return $coupon ?? throw ProviderApiException::notFound('Coupon not found.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, array $rules, ?Coupon $existing, int $used): array
    {
        $validator = Validator::make($request->all(), $rules);
        $validator->after(fn ($v) => CouponRules::after($v, $request->all(), $existing, $used));

        return $validator->validate();
    }

    /** @return array<string, mixed> only the columns that were sent */
    private function columns(array $data): array
    {
        $columns = array_intersect_key($data, array_flip(['type', 'value', 'usage_limit', 'max_discount', 'min_order_amount']));

        if (array_key_exists('expires_at', $data)) {
            $columns['expires_at'] = $data['expires_at'] === null ? null : \Carbon\Carbon::parse($data['expires_at'])->utc();
        }

        return $columns;
    }

    /** @return array<string, mixed> */
    private function describe(Coupon $c, int $used): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'type' => $c->type,
            'value' => $c->value,
            'used_count' => $used,
            'usage_limit' => $c->usage_limit,
            'max_discount' => $c->max_discount,
            'min_order_amount' => $c->min_order_amount,
            'expires_at' => $c->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            // Empty: every activity of the provider.
            'activity_ids' => $c->activities->pluck('id')->sort()->values()->all(),
            'status' => $this->coupons->status($c),
        ];
    }
}
