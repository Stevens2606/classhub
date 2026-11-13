<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\MidtransService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /** Receives Midtrans payment notifications. The signature proves the call really comes from Midtrans. */
    public function handle(Request $request, MidtransService $midtrans): JsonResponse
    {
        $payload = $request->all();

        foreach (['order_id', 'status_code', 'gross_amount', 'signature_key', 'transaction_status'] as $field) {
            if (! isset($payload[$field])) {
                return response()->json(['message' => 'Invalid payload.'], 400);
            }
        }

        if (! $midtrans->signatureIsValid($payload)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $order = Order::where('order_code', $payload['order_id'])->first();
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if ((int) round((float) $payload['gross_amount']) !== $order->gross_amount) {
            return response()->json(['message' => 'Amount mismatch.'], 400);
        }

        // Midtrans may retry notifications: a paid order is never changed again.
        if ($order->status === Order::PAID) {
            return response()->json(['message' => 'OK']);
        }

        $status = $this->mapStatus($payload['transaction_status'], $payload['fraud_status'] ?? null);
        if ($status === Order::PAID) {
            $order->markPaid();
        } elseif ($status === Order::FAILED) {
            $order->update(['status' => Order::FAILED]);
        }

        return response()->json(['message' => 'OK']);
    }

    private function mapStatus(string $transactionStatus, ?string $fraudStatus): string
    {
        return match ($transactionStatus) {
            'settlement' => Order::PAID,
            'capture' => $fraudStatus === 'challenge' ? Order::PENDING : Order::PAID,
            'deny', 'cancel', 'expire', 'failure' => Order::FAILED,
            default => Order::PENDING,
        };
    }
}
