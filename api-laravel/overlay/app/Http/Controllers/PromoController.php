<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromoController extends Controller
{
    /** Shows the price a student would pay with a promo code, without creating an order. */
    public function validateCode(Request $request, PricingService $pricing): JsonResponse
    {
        $data = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'promo_code' => 'required|string|max:50',
        ]);

        $course = Course::findOrFail($data['course_id']);
        $quote = $pricing->quote($course, $pricing->resolvePromo($data['promo_code']));

        return response()->json(['data' => $quote]);
    }
}
