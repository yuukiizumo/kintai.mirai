<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_open_registration_screen_from_guest_route(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('新規登録')
            ->assertDontSee('管理番号')
            ->assertDontSee('時給')
            ->assertDontSee('有給残日数')
            ->assertSee('勤務しない');
    }

    public function test_guest_can_register_regular_user(): void
    {
        $response = $this->post('/register', [
            'name' => '山田 太郎',
            'hire_date' => '2026-06-01',
            'email' => 'new-user@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'department' => '在宅',
            'business_category' => '在宅PC',
            'work_style' => 'B型',
            'commute_limit_days' => '-4日',
            'height_cm' => '170.5',
            'weight_kg' => '60.2',
            'gender' => '男',
            'workday_settings' => collect(range(1, 6))->mapWithKeys(fn (int $weekday) => [
                (string) $weekday => [
                    'default_clock_in' => '09:00',
                    'default_clock_out' => '18:00',
                    'default_break_minutes' => 60,
                    'is_working_day' => $weekday !== 3,
                ],
            ])->all(),
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'name' => '山田 太郎',
            'email' => 'new-user@example.com',
            'role' => 'user',
            'department' => '在宅',
            'business_category' => '在宅PC',
            'work_style' => 'B型',
            'commute_limit_days' => '-4日',
            'management_number' => null,
            'hourly_wage' => null,
        ]);

        $user = User::where('email', 'new-user@example.com')->first();

        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->normalizedWorkdaySettings()['3']['is_working_day']);
    }

    public function test_guest_can_open_admin_registration_screen(): void
    {
        $this->get('/admin/register')
            ->assertOk()
            ->assertSee('管理者新規登録')
            ->assertSee('合言葉');
    }

    public function test_guest_can_register_admin_with_passphrase(): void
    {
        $response = $this->post('/admin/register', [
            'name' => 'Admin User',
            'email' => 'new-admin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'passphrase' => 'iamadmin',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'name' => 'Admin User',
            'email' => 'new-admin@example.com',
            'role' => 'admin',
        ]);
    }

    public function test_guest_cannot_register_admin_with_wrong_passphrase(): void
    {
        $response = $this->from('/admin/register')->post('/admin/register', [
            'name' => 'Wrong Admin',
            'email' => 'wrong-admin@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'passphrase' => 'wrong',
        ]);

        $response
            ->assertRedirect('/admin/register')
            ->assertSessionHasErrors('passphrase');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', [
            'email' => 'wrong-admin@example.com',
        ]);
    }
}
