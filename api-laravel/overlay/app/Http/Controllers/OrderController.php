<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Order;
use App\Services\MidtransService;
use App\Services\PricingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with('course:id,title')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->get()
            ->map(fn (Order $order) => $this->present($order));

        return response()->json(['data' => $orders]);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);

        return response()->json(['data' => $this->present($order->load('course:id,title'))]);
    }

    public function store(Request $request, PricingService $pricing, MidtransService $midtrans): JsonResponse
    {
        $data = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'promo_code' => 'nullable|string|max:50',
        ]);

        $user = $request->user();
        $course = Course::where('is_active', true)->findOrFail($data['course_id']);

        $alreadyPaid = Order::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->where('status', Order::PAID)
            ->exists();
        if ($alreadyPaid) {
            return response()->json(['message' => 'You are already enrolled in this course.'], 409);
        }

        try {
            // Everything below commits together: if the payment gateway call fails,
            // the order and the promo usage are rolled back.
            $order = DB::transaction(function () use ($data, $user, $course, $pricing, $midtrans) {
                $promo = $pricing->resolvePromo($data['promo_code'] ?? null, lock: true);
                $quote = $pricing->quote($course, $promo);

                $order = Order::create([
                    'order_code' => 'CH-'.Str::upper(Str::random(10)),
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'promo_id' => $promo?->id,
                    'original_amount' => $quote['original'],
                    'discount_amount' => $quote['discount'],
                    'gross_amount' => $quote['total'],
                    'status' => Order::PENDING,
                ]);

                $promo?->increment('used_count');

                if ($quote['total'] === 0) {
                    $order->markPaid(); // fully discounted: nothing to pay

                    return $order;
                }

                if ($midtrans->isConfigured()) {
                    $snap = $midtrans->createSnap($order, $user, $course);
                    $order->update(['snap_token' => $snap['token'], 'redirect_url' => $snap['redirect_url']]);
                }

                return $order;
            });
        } catch (ConnectionException|RequestException $e) {
            Log::error('Midtrans request failed: '.$e->getMessage());

            return response()->json(['message' => 'The payment service is unavailable. Please try again.'], 502);
        }

        return response()->json([
            'data' => $this->present($order->load('course:id,title')),
            'payment_configured' => $midtrans->isConfigured(),
        ], 201);
    }

    /** Local development only: pretends Midtrans reported a successful payment. */
    public function simulatePaid(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        if ($order->status === Order::PENDING) {
            $order->markPaid();
        }

        return response()->json(['data' => $this->present($order->fresh()->load('course:id,title'))]);
    }

    private function present(Order $order): array
    {
        return [
            'order_code' => $order->order_code,
            'course' => $order->course?->only(['id', 'title']),
            'original_amount' => $order->original_amount,
            'discount_amount' => $order->discount_amount,
            'gross_amount' => $order->gross_amount,
            'status' => $order->status,
            'snap_token' => $order->snap_token,
            'redirect_url' => $order->redirect_url,
            'paid_at' => $order->paid_at,
        ];
    }
}
