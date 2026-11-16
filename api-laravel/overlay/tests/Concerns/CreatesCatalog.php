<?php

namespace Tests\Concerns;

use App\Models\Course;
use App\Models\Order;
use App\Models\Promo;
use App\Models\User;
use Illuminate\Support\Str;

/** Small helpers that build the data most tests need. */
trait CreatesCatalog
{
    protected function course(int $price = 150000, array $attributes = []): Course
    {
        return Course::create(array_merge([
            'title' => 'Mathematics Fundamentals',
            'description' => 'Algebra and linear equations.',
            'price' => $price,
        ], $attributes));
    }

    protected function promo(string $code = 'WELCOME10', string $type = 'percent', int $value = 10, array $attributes = []): Promo
    {
        return Promo::create(array_merge([
            'code' => $code,
            'type' => $type,
            'value' => $value,
        ], $attributes));
    }

    protected function order(User $user, Course $course, string $status = Order::PENDING, ?int $amount = null): Order
    {
        $amount ??= $course->price;

        return Order::create([
            'order_code' => 'CH-'.Str::upper(Str::random(10)),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'original_amount' => $course->price,
            'discount_amount' => $course->price - $amount,
            'gross_amount' => $amount,
            'status' => $status,
            'paid_at' => $status === Order::PAID ? now() : null,
        ]);
    }
}
