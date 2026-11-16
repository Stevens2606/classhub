<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use CreatesCatalog;
    use RefreshDatabase;

    private function student(): User
    {
        return tap(User::factory()->create(), fn (User $user) => Sanctum::actingAs($user));
    }

    public function test_creating_an_order_requires_a_login(): void
    {
        $course = $this->course();

        $this->postJson('/api/orders', ['course_id' => $course->id])->assertStatus(401);
    }

    public function test_order_without_a_promo_costs_the_full_price(): void
    {
        $course = $this->course(150000);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.original_amount', 150000)
            ->assertJsonPath('data.discount_amount', 0)
            ->assertJsonPath('data.gross_amount', 150000)
            ->assertJsonPath('payment_configured', false);

        $this->assertDatabaseCount('orders', 1);
        $this->assertStringStartsWith('CH-', Order::first()->order_code);
    }

    public function test_percent_promo_lowers_the_price_and_counts_a_use(): void
    {
        $course = $this->course(150000);
        $promo = $this->promo('WELCOME10', 'percent', 10);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => 'WELCOME10'])
            ->assertCreated()
            ->assertJsonPath('data.discount_amount', 15000)
            ->assertJsonPath('data.gross_amount', 135000);

        $this->assertSame(1, $promo->fresh()->used_count);
        $this->assertSame($promo->id, Order::first()->promo_id);
    }

    public function test_promo_code_is_not_case_sensitive(): void
    {
        $course = $this->course(150000);
        $this->promo('WELCOME10', 'percent', 10);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => ' welcome10 '])
            ->assertCreated()
            ->assertJsonPath('data.gross_amount', 135000);
    }

    public function test_fixed_promo_takes_a_fixed_amount_off(): void
    {
        $course = $this->course(250000);
        $this->promo('HEMAT25K', 'fixed', 25000);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => 'HEMAT25K'])
            ->assertCreated()
            ->assertJsonPath('data.gross_amount', 225000);
    }

    public function test_unknown_promo_is_rejected_and_no_order_is_created(): void
    {
        $course = $this->course();
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => 'NOPE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['promo_code']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_expired_inactive_and_used_up_promos_are_rejected(): void
    {
        $course = $this->course();
        $this->promo('EXPIRED', 'percent', 10, ['expires_at' => now()->subDay()]);
        $this->promo('OFF', 'percent', 10, ['is_active' => false]);
        $this->promo('USEDUP', 'percent', 10, ['max_uses' => 1, 'used_count' => 1]);
        $this->student();

        foreach (['EXPIRED', 'OFF', 'USEDUP'] as $code) {
            $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => $code])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['promo_code']);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_fully_discounted_order_is_paid_immediately(): void
    {
        $course = $this->course(150000);
        $this->promo('FREE', 'fixed', 200000); // bigger than the price: capped at the price
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => 'FREE'])
            ->assertCreated()
            ->assertJsonPath('data.gross_amount', 0)
            ->assertJsonPath('data.status', 'paid');

        $this->assertNotNull(Order::first()->paid_at);
    }

    public function test_an_unknown_course_is_rejected(): void
    {
        $this->student();

        $this->postJson('/api/orders', ['course_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['course_id']);
    }

    public function test_an_inactive_course_cannot_be_bought(): void
    {
        $course = $this->course(100000, ['is_active' => false]);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id])->assertNotFound();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_student_cannot_buy_the_same_course_twice(): void
    {
        $course = $this->course();
        $student = $this->student();
        $this->order($student, $course, Order::PAID);

        $this->postJson('/api/orders', ['course_id' => $course->id])->assertStatus(409);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_with_a_midtrans_key_the_order_gets_a_payment_page(): void
    {
        config(['classhub.midtrans.server_key' => 'SB-Mid-server-test']);
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response([
            'token' => 'snap-token-123',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-123',
        ], 201)]);
        $course = $this->course(150000);
        $this->promo('WELCOME10', 'percent', 10);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => 'WELCOME10'])
            ->assertCreated()
            ->assertJsonPath('payment_configured', true)
            ->assertJsonPath('data.snap_token', 'snap-token-123')
            ->assertJsonPath('data.redirect_url', 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-123');

        // Midtrans is asked to charge the discounted amount, not the list price.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'sandbox.midtrans.com')
            && $request->hasHeader('Authorization')
            && $request['transaction_details']['gross_amount'] === 135000);
    }

    public function test_when_midtrans_fails_the_order_and_the_promo_use_are_rolled_back(): void
    {
        config(['classhub.midtrans.server_key' => 'SB-Mid-server-test']);
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['error' => 'down'], 500)]);
        $course = $this->course(150000);
        $promo = $this->promo('WELCOME10', 'percent', 10);
        $this->student();

        $this->postJson('/api/orders', ['course_id' => $course->id, 'promo_code' => 'WELCOME10'])
            ->assertStatus(502);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $promo->fresh()->used_count);
    }

    public function test_a_student_only_sees_their_own_orders(): void
    {
        $course = $this->course();
        $other = User::factory()->create();
        $theirs = $this->order($other, $course);
        $me = $this->student();
        $mine = $this->order($me, $course);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_code', $mine->order_code);

        $this->getJson("/api/orders/{$mine->order_code}")->assertOk();
        $this->getJson("/api/orders/{$theirs->order_code}")->assertNotFound();
    }

    public function test_the_payment_simulator_does_not_exist_outside_local_mode(): void
    {
        $course = $this->course();
        $student = $this->student();
        $order = $this->order($student, $course);

        $this->postJson("/api/dev/orders/{$order->order_code}/simulate-paid")->assertNotFound();

        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }
}
