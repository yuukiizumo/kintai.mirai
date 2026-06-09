<?php

namespace Tests\Feature;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_has_forgot_password_link(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('パスワードを忘れた方')
            ->assertSee('/forgot-password');
    }

    public function test_forgot_password_request_does_not_expose_reset_link(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'reset@example.com',
        ]);

        $this->post('/forgot-password', [
            'email' => $user->email,
        ])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', 'password_reset_requested')
            ->assertSessionMissing('reset_url');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);

        Mail::assertSent(PasswordResetLinkMail::class, function (PasswordResetLinkMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->resetUrl, '/reset-password/')
                && str_contains($mail->resetUrl, 'email=reset%40example.com');
        });
    }

    public function test_forgot_password_request_does_not_reveal_unknown_email(): void
    {
        Mail::fake();

        $this->post('/forgot-password', [
            'email' => 'unknown@example.com',
        ])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', 'password_reset_requested')
            ->assertSessionMissing('reset_url');

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'unknown@example.com',
        ]);

        Mail::assertNothingSent();
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'valid-reset@example.com',
            'password' => Hash::make('old-password'),
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('valid-token'),
            'created_at' => now(),
        ]);

        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => 'valid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'パスワードを再設定しました。新しいパスワードでログインしてください。');

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'email' => 'invalid-reset@example.com',
        ]);

        $this->post('/reset-password', [
            'email' => $user->email,
            'token' => 'invalid-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertSessionHasErrors('email');
    }
}
