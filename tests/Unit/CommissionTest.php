<?php

namespace Tests\Unit;

use App\Models\Provider;
use App\Support\Money\Commission;
use PHPUnit\Framework\TestCase;

/** The platform's commission on one booking: a percentage of what the provider kept, or a fixed amount per booking (never more than the booking). */
class CommissionTest extends TestCase
{
    public function test_a_percentage_is_taken_from_the_amount_and_rounded_to_two_decimals(): void
    {
        $this->assertSame(100.0, Commission::calculate('percentage', 10, 1000));
        $this->assertSame(3.33, Commission::calculate('percentage', 10, 33.33));
        $this->assertSame(7.5, Commission::calculate('percentage', 7.5, 100));
        $this->assertSame(0.0, Commission::calculate('percentage', 0, 1000));
        $this->assertSame(1000.0, Commission::calculate('percentage', 100, 1000));
    }

    public function test_a_fixed_commission_is_the_same_whatever_the_booking_but_never_more_than_it(): void
    {
        $this->assertSame(25.0, Commission::calculate('fixed', 25, 1000));
        $this->assertSame(25.0, Commission::calculate('fixed', 25, 30));
        $this->assertSame(20.0, Commission::calculate('fixed', 25, 20), 'Never more than the booking itself');
        $this->assertSame(0.0, Commission::calculate('fixed', 0, 500));
    }

    public function test_nothing_is_taken_from_nothing(): void
    {
        foreach (['percentage', 'fixed'] as $type) {
            $this->assertSame(0.0, Commission::calculate($type, 10, 0));
            $this->assertSame(0.0, Commission::calculate($type, 10, -5));
        }
    }

    public function test_the_two_types_are_the_ones_that_can_be_chosen(): void
    {
        $this->assertSame(['percentage', 'fixed'], Commission::TYPES);
    }

    public function test_a_provider_uses_its_own_terms_for_every_booking(): void
    {
        $percent = new Provider(['commission_type' => 'percentage', 'commission_rate' => 12.5]);
        $fixed = new Provider(['commission_type' => 'fixed', 'commission_rate' => 40]);

        $this->assertSame(125.0, $percent->commissionOn(1000));
        $this->assertSame(40.0, $fixed->commissionOn(1000));
        $this->assertSame(40.0, $fixed->commissionOn(400), 'The same for any activity and any booking size');
    }
}
