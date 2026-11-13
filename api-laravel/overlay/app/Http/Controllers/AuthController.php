<?php

namespace App\Http\Controllers;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|max:100',
        ]);

        $user = User::create($data);
        $this->sendOtp($user);

        return response()->json([
            'message' => 'Account created. A 6-digit verification code was sent to your email.',
        ], 201);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);

        // Same answer whether or not the account exists, so emails cannot be enumerated.
        $user = User::where('email', $data['email'])->whereNull('email_verified_at')->first();
        if ($user) {
            $this->sendOtp($user);
        }

        return response()->json(['message' => 'If the account exists and is not verified, a new code was sent.']);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:6',
        ]);

        $invalid = response()->json(['message' => 'The code is invalid or has expired.'], 422);

        $otp = OtpCode::where('email', $data['email'])->first();
        $user = User::where('email', $data['email'])->first();
        if (! $otp || ! $user || $otp->expires_at->isPast() || $otp->attempts >= config('classhub.otp.max_attempts')) {
            return $invalid;
        }

        if (! Hash::check($data['otp'], $otp->code_hash)) {
            $otp->increment('attempts');

            return $invalid;
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $otp->delete();

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }
        if ($user->email_verified_at === null) {
            return response()->json(['message' => 'Please verify your email with the OTP code first.'], 403);
        }

        return response()->json([
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->present($request->user())]);
    }

    private function sendOtp(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $ttl = (int) config('classhub.otp.ttl_minutes');

        // Only a hash of the code is stored, so a database leak does not reveal valid codes.
        OtpCode::updateOrCreate(['email' => $user->email], [
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        Mail::to($user->email)->send(new OtpMail($code, $ttl));
    }

    private function present(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
