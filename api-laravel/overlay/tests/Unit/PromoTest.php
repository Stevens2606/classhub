<?php

namespace Tests\Unit;

use App\Models\Promo;
use Tests\TestCase;

class PromoTest extends TestCase
{
    private function promo(array $attributes): Promo
    {
        return new Promo(array_merge(['type' => 'percent', 'value' => 10, 'is_active' => true], $attributes));
    }

    public function test_percent_discount(): void
    {
        $this->assertSame(15000, $this->promo(['type' => 'percent', 'value' => 10])->discountFor(150000));
    }

    public function test_percent_discount_rounds_down_to_whole_rupiah(): void
    {
        $this->assertSame(9999, $this->promo(['type' => 'percent', 'value' => 10])->discountFor(99999));
    }

    public function test_percent_above_100_is_capped_at_the_price(): void
    {
        $this->assertSame(50000, $this->promo(['type' => 'percent', 'value' => 150])->discountFor(50000));
    }

    public function test_fixed_discount(): void
    {
        $this->assertSame(25000, $this->promo(['type' => 'fixed', 'value' => 25000])->discountFor(250000));
    }

    public function test_fixed_discount_never_exceeds_the_price(): void
    {
        $this->assertSame(10000, $this->promo(['type' => 'fixed', 'value' => 25000])->discountFor(10000));
    }

    public function test_active_promo_without_limits_is_usable(): void
    {
        $this->assertTrue($this->promo([])->isUsable());
    }

    public function test_inactive_promo_is_not_usable(): void
    {
        $this->assertFalse($this->promo(['is_active' => false])->isUsable());
    }

    public function test_expired_promo_is_not_usable(): void
    {
        $this->assertFalse($this->promo(['expires_at' => now()->subMinute()])->isUsable());
    }

    public function test_promo_that_has_not_expired_is_usable(): void
    {
        $this->assertTrue($this->promo(['expires_at' => now()->addDay()])->isUsable());
    }

    public function test_promo_with_all_uses_taken_is_not_usable(): void
    {
        $this->assertFalse($this->promo(['max_uses' => 5, 'used_count' => 5])->isUsable());
    }

    public function test_promo_with_uses_left_is_usable(): void
    {
        $this->assertTrue($this->promo(['max_uses' => 5, 'used_count' => 4])->isUsable());
    }
}
