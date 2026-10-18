<?php

namespace Tests\Feature;

use App\Mail\VerfifyCodeRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ResendMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_user_can_resend_a_hashed_verification_code(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();
        $sentAt = now();

        $this->postJson('/api/resend-mail', ['email' => $user->email])
            ->assertOk()
            ->assertExactJson([
                'message' => 'verification code resent successfully',
            ]);

        $queuedMail = null;
        Mail::assertQueued(VerfifyCodeRegister::class, function (VerfifyCodeRegister $mail) use ($user, &$queuedMail): bool {
            $queuedMail = $mail;

            return $mail->hasTo($user->email);
        });

        $user->refresh();
        $this->assertNotNull($user->code_verify_email);
        $this->assertNotNull($user->expired_verify_email);
        $this->assertLessThanOrEqual(
            1,
            abs($user->expired_verify_email->timestamp - $sentAt->copy()->addMinutes(10)->timestamp),
        );
        $this->assertInstanceOf(VerfifyCodeRegister::class, $queuedMail);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $queuedMail->code);
        $this->assertTrue(Hash::check($queuedMail->code, $user->code_verify_email));

        $this->postJson('/api/verify-mail', [
            'email' => $user->email,
            'code' => $queuedMail->code,
        ])->assertOk()->assertExactJson([
            'message' => 'email verified successfully',
        ]);
    }

    public function test_resend_returns_not_found_for_an_unknown_email(): void
    {
        Mail::fake();

        $this->postJson('/api/resend-mail', ['email' => 'missing@example.com'])
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'email not found',
            ]);
    }

    public function test_resend_rejects_an_already_verified_email(): void
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->postJson('/api/resend-mail', ['email' => $user->email])
            ->assertStatus(400)
            ->assertExactJson([
                'message' => 'email already verified',
            ]);

        Mail::assertNothingQueued();
    }

    public function test_resend_requires_a_valid_email_address(): void
    {
        $this->postJson('/api/resend-mail', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_resend_requires_the_email_field(): void
    {
        $this->postJson('/api/resend-mail')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_verification_rejects_an_expired_code(): void
    {
        $user = User::factory()->unverified()->create([
            'code_verify_email' => Hash::make('123456'),
            'expired_verify_email' => now()->subMinute(),
        ]);

        $this->postJson('/api/verify-mail', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertStatus(401)->assertExactJson([
            'message' => 'code is expired',
        ]);
    }

    public function test_resend_is_rate_limited(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/resend-mail', ['email' => $user->email])
                ->assertOk();
        }

        $this->postJson('/api/resend-mail', ['email' => $user->email])
            ->assertStatus(429);
    }
}
