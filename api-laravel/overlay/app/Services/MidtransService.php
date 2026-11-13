<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Midtrans Snap API (sandbox by default).
 */
class MidtransService
{
    public function isConfigured(): bool
    {
        return filled(config('classhub.midtrans.server_key'));
    }

    /**
     * Creates a Snap transaction and returns its token and payment page URL.
     *
     * @return array{token:string, redirect_url:string}
     *
     * @throws \Illuminate\Http\Client\RequestException
     */
    public function createSnap(Order $order, User $user, Course $course): array
    {
        $response = Http::withBasicAuth((string) config('classhub.midtrans.server_key'), '')
            ->acceptJson()
            ->timeout(10)
            ->post(config('classhub.midtrans.snap_url'), [
                'transaction_details' => [
                    'order_id' => $order->order_code,
                    'gross_amount' => $order->gross_amount,
                ],
                'item_details' => [[
                    'id' => 'course-'.$course->id,
                    'price' => $order->gross_amount,
                    'quantity' => 1,
                    'name' => mb_substr($course->title, 0, 50),
                ]],
                'customer_details' => [
                    'first_name' => $user->name,
                    'email' => $user->email,
                ],
            ])
            ->throw();

        return [
            'token' => $response->json('token'),
            'redirect_url' => $response->json('redirect_url'),
        ];
    }

    /** Verifies the signature Midtrans puts on every payment notification. */
    public function signatureIsValid(array $payload): bool
    {
        $serverKey = (string) config('classhub.midtrans.server_key');
        if ($serverKey === '') {
            return false;
        }

        $expected = hash('sha512',
            ($payload['order_id'] ?? '').($payload['status_code'] ?? '').($payload['gross_amount'] ?? '').$serverKey);

        return hash_equals($expected, (string) ($payload['signature_key'] ?? ''));
    }
}
