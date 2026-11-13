<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Promo;
use Illuminate\Validation\ValidationException;

class PricingService
{
    /**
     * Finds a promo by code and makes sure it can still be used.
     * Pass $lock = true inside a transaction to stop two buyers from using the last slot at once.
     */
    public function resolvePromo(?string $code, bool $lock = false): ?Promo
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $query = Promo::where('code', strtoupper(trim($code)));
        if ($lock) {
            $query->lockForUpdate();
        }
        $promo = $query->first();

        if ($promo === null || ! $promo->isUsable()) {
            throw ValidationException::withMessages([
                'promo_code' => ['This promo code is invalid or no longer available.'],
            ]);
        }

        return $promo;
    }

    /** @return array{original:int, discount:int, total:int} */
    public function quote(Course $course, ?Promo $promo): array
    {
        $original = (int) $course->price;
        $discount = $promo?->discountFor($original) ?? 0;

        return [
            'original' => $original,
            'discount' => $discount,
            'total' => $original - $discount,
        ];
    }
}
