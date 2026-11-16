<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use CreatesCatalog;
    use RefreshDatabase;

    public function test_a_missing_or_blank_code_means_no_promo(): void
    {
        $service = new PricingService();

        $this->assertNull($service->resolvePromo(null));
        $this->assertNull($service->resolvePromo('   '));
    }

    public function test_a_known_code_is_found_whatever_its_case_or_spacing(): void
    {
        $promo = $this->promo('WELCOME10');

        $this->assertTrue((new PricingService())->resolvePromo(' welcome10 ')->is($promo));
    }

    public function test_an_unknown_code_raises_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);

        (new PricingService())->resolvePromo('NOPE');
    }

    public function test_quote_subtracts_the_discount(): void
    {
        $service = new PricingService();
        $course = $this->course(150000);
        $promo = $this->promo('WELCOME10', 'percent', 10);

        $this->assertSame(
            ['original' => 150000, 'discount' => 15000, 'total' => 135000],
            $service->quote($course, $promo)
        );
        $this->assertSame(
            ['original' => 150000, 'discount' => 0, 'total' => 150000],
            $service->quote($course, null)
        );
    }

    public function test_the_promo_validate_endpoint_shows_the_price_without_creating_an_order(): void
    {
        $course = $this->course(250000);
        $promo = $this->promo('HEMAT25K', 'fixed', 25000);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/promos/validate', ['course_id' => $course->id, 'promo_code' => 'HEMAT25K'])
            ->assertOk()
            ->assertJsonPath('data.original', 250000)
            ->assertJsonPath('data.discount', 25000)
            ->assertJsonPath('data.total', 225000);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $promo->fresh()->used_count);
    }

    public function test_the_promo_validate_endpoint_rejects_a_bad_code(): void
    {
        $course = $this->course();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/promos/validate', ['course_id' => $course->id, 'promo_code' => 'NOPE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['promo_code']);
    }
}
