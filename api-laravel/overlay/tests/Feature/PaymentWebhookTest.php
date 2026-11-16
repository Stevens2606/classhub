<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use CreatesCatalog;
    use RefreshDatabase;

    private const SERVER_KEY = 'test-server-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['classhub.midtrans.server_key' => self::SERVER_KEY]);
    }

    private function pendingOrder(int $amount = 135000): Order
    {
        return $this->order(User::factory()->create(), $this->course(150000), Order::PENDING, $amount);
    }

    /** Builds a Midtrans-style notification, signed the way Midtrans signs it. */
    private function notification(Order $order, array $overrides = [], string $key = self::SERVER_KEY): array
    {
        $payload = array_merge([
            'order_id' => $order->order_code,
            'status_code' => '200',
            'gross_amount' => number_format($order->gross_amount, 2, '.', ''),
            'transaction_status' => 'settlement',
        ], $overrides);

        $payload['signature_key'] ??= hash('sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$key);

        return $payload;
    }

    public function test_a_valid_settlement_marks_the_order_paid(): void
    {
        $order = $this->pendingOrder();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order))->assertOk();

        $order->refresh();
        $this->assertSame(Order::PAID, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_a_wrong_signature_is_refused(): void
    {
        $order = $this->pendingOrder();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, [], 'some-other-key'))
            ->assertStatus(403);

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_every_notification_is_refused_when_no_server_key_is_configured(): void
    {
        config(['classhub.midtrans.server_key' => '']);
        $order = $this->pendingOrder();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, [], ''))
            ->assertStatus(403);

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_a_payload_with_missing_fields_is_a_bad_request(): void
    {
        $this->postJson('/api/payments/midtrans/notification', ['order_id' => 'CH-X'])->assertStatus(400);
    }

    public function test_an_unknown_order_returns_404(): void
    {
        $order = $this->pendingOrder();
        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, ['order_id' => 'CH-DOESNOTEXIST']))
            ->assertNotFound();
    }

    public function test_an_amount_that_does_not_match_the_order_is_refused(): void
    {
        $order = $this->pendingOrder(135000);

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, ['gross_amount' => '1.00']))
            ->assertStatus(400);

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_a_retried_notification_changes_nothing(): void
    {
        $order = $this->pendingOrder();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order))->assertOk();
        $firstPaidAt = $order->fresh()->paid_at;

        $this->travel(5)->minutes();
        $this->postJson('/api/payments/midtrans/notification', $this->notification($order))->assertOk();

        $this->assertEquals($firstPaidAt, $order->fresh()->paid_at);
        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_an_expired_payment_marks_the_order_failed(): void
    {
        $order = $this->pendingOrder();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, ['transaction_status' => 'expire']))
            ->assertOk();

        $this->assertSame(Order::FAILED, $order->fresh()->status);
    }

    public function test_a_paid_order_is_never_downgraded(): void
    {
        $order = $this->pendingOrder();
        $this->postJson('/api/payments/midtrans/notification', $this->notification($order))->assertOk();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, ['transaction_status' => 'expire']))
            ->assertOk();

        $this->assertSame(Order::PAID, $order->fresh()->status);
    }

    public function test_a_card_payment_under_fraud_review_stays_pending_until_accepted(): void
    {
        $order = $this->pendingOrder();

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, [
            'transaction_status' => 'capture', 'fraud_status' => 'challenge',
        ]))->assertOk();
        $this->assertSame(Order::PENDING, $order->fresh()->status);

        $this->postJson('/api/payments/midtrans/notification', $this->notification($order, [
            'transaction_status' => 'capture', 'fraud_status' => 'accept',
        ]))->assertOk();
        $this->assertSame(Order::PAID, $order->fresh()->status);
    }
}
