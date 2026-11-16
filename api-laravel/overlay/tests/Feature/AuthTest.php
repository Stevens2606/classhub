<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'student@example.com';

    /** Registers a student and returns the OTP code that was emailed. */
    private function registerAndGetCode(string $email = self::EMAIL): string
    {
        Mail::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Student One',
            'email' => $email,
            'password' => 'secret-pass-1',
        ])->assertCreated();

        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (&$code, $email) {
            $code = $mail->code;

            return $mail->hasTo($email);
        });

        return $code;
    }

    private function wrongCodeFor(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    public function test_register_creates_an_unverified_user_and_emails_a_code(): void
    {
        $code = $this->registerAndGetCode();

        $user = User::where('email', self::EMAIL)->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_password_and_otp_are_stored_hashed(): void
    {
        $code = $this->registerAndGetCode();

        $user = User::where('email', self::EMAIL)->first();
        $this->assertNotSame('secret-pass-1', $user->password);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));

        $otp = OtpCode::where('email', self::EMAIL)->first();
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertTrue(Hash::check($code, $otp->code_hash));
    }

    public function test_register_validates_the_input(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/auth/register', [
            'name' => 'Short', 'email' => 'short@example.com', 'password' => '1234567',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson('/api/auth/register', [
            'name' => 'Copy', 'email' => self::EMAIL, 'password' => 'secret-pass-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_correct_code_verifies_the_email_and_returns_a_working_token(): void
    {
        $code = $this->registerAndGetCode();

        $response = $this->postJson('/api/auth/verify-otp', ['email' => self::EMAIL, 'otp' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);

        $this->assertNotNull(User::where('email', self::EMAIL)->first()->email_verified_at);
        $this->assertDatabaseMissing('otp_codes', ['email' => self::EMAIL]);

        $this->withToken($response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', self::EMAIL);
    }

    public function test_wrong_code_is_rejected_and_counted(): void
    {
        $code = $this->registerAndGetCode();

        $this->postJson('/api/auth/verify-otp', ['email' => self::EMAIL, 'otp' => $this->wrongCodeFor($code)])
            ->assertStatus(422);

        $this->assertSame(1, OtpCode::where('email', self::EMAIL)->first()->attempts);
        $this->assertNull(User::where('email', self::EMAIL)->first()->email_verified_at);
    }

    public function test_code_is_locked_after_too_many_wrong_attempts(): void
    {
        $code = $this->registerAndGetCode();

        for ($i = 0; $i < config('classhub.otp.max_attempts'); $i++) {
            $this->postJson('/api/auth/verify-otp', ['email' => self::EMAIL, 'otp' => $this->wrongCodeFor($code)])
                ->assertStatus(422);
        }

        // Even the right code no longer works: the student must ask for a new one.
        $this->postJson('/api/auth/verify-otp', ['email' => self::EMAIL, 'otp' => $code])->assertStatus(422);
        $this->assertNull(User::where('email', self::EMAIL)->first()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->registerAndGetCode();

        $this->travel(config('classhub.otp.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/auth/verify-otp', ['email' => self::EMAIL, 'otp' => $code])->assertStatus(422);
    }

    public function test_verify_otp_validates_the_code_format(): void
    {
        $this->postJson('/api/auth/verify-otp', ['email' => self::EMAIL, 'otp' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);
    }

    public function test_login_is_refused_until_the_email_is_verified(): void
    {
        $this->registerAndGetCode();

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'secret-pass-1'])
            ->assertStatus(403);
    }

    public function test_login_with_a_wrong_password_returns_401(): void
    {
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'not-the-password'])
            ->assertStatus(401);
    }

    public function test_login_returns_a_token_for_a_verified_user(): void
    {
        User::factory()->create(['email' => self::EMAIL]); // factory password is "password"

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_deletes_the_token(): void
    {
        $token = User::factory()->create()->createToken('api')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_resend_gives_the_same_answer_for_unknown_and_known_accounts(): void
    {
        Mail::fake();
        User::factory()->unverified()->create(['email' => self::EMAIL]);

        $known = $this->postJson('/api/auth/resend-otp', ['email' => self::EMAIL])->assertOk();
        $unknown = $this->postJson('/api/auth/resend-otp', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Mail::assertSent(OtpMail::class, 1); // only the real, unverified account got an email
    }

    public function test_resend_sends_nothing_to_an_already_verified_account(): void
    {
        Mail::fake();
        User::factory()->create(['email' => self::EMAIL]);

        $this->postJson('/api/auth/resend-otp', ['email' => self::EMAIL])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_the_otp_email_shows_the_code(): void
    {
        (new OtpMail('482913', 10))->assertSeeInHtml('482913');
    }
}
