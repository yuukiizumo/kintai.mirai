<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RetiredUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retire_user_and_see_history_in_retired_user_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo('2026-07-01 12:00:00');
        $user = User::factory()->create(['role' => 'user', 'name' => '退職 太郎']);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => '最終勤務',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-02',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/users/{$user->id}/retire", [
                'retirement_date' => '2026-06-30',
            ])
            ->assertOk()
            ->assertJsonPath('retirement_date', '2026-06-30');

        $user->refresh();
        $this->assertSame('2026-06-30', $user->retirement_date->format('Y-m-d'));
        $this->assertNotNull($user->retired_at);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records?month=2026-06&date=2026-06-01')
            ->assertOk()
            ->assertJsonMissingPath('users.0.id')
            ->assertJsonCount(0, 'records');

        $this->actingAs($admin)
            ->getJson("/api/retired-users?user_id={$user->id}")
            ->assertOk()
            ->assertJsonPath('users.0.name', '退職 太郎')
            ->assertJsonPath('records.0.work_date', '2026-06-01')
            ->assertJsonPath('records.0.note', '最終勤務')
            ->assertJsonPath('requests.0.type', 'paid_leave');
    }

    public function test_admin_can_restore_retired_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'retirement_date' => '2026-06-30',
            'retired_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson("/api/users/{$user->id}/restore")
            ->assertOk()
            ->assertJsonPath('retirement_date', '');

        $this->assertNull($user->refresh()->retired_at);
        $this->assertNull($user->retirement_date);
    }

    public function test_admin_can_force_delete_only_retired_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo('2026-07-01 12:00:00');
        $activeUser = User::factory()->create(['role' => 'user']);
        $retiredUser = User::factory()->create([
            'role' => 'user',
            'retirement_date' => '2026-06-30',
            'retired_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$activeUser->id}/force-delete")
            ->assertStatus(422);

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$retiredUser->id}/force-delete")
            ->assertNoContent();

        $this->assertDatabaseMissing('users', [
            'id' => $retiredUser->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $activeUser->id,
        ]);
    }

    public function test_retired_user_cannot_login(): void
    {
        $this->travelTo('2026-07-01 12:00:00');
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('password'),
            'retirement_date' => '2026-06-30',
            'retired_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_future_retirement_date_keeps_user_active_with_scheduled_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user', 'name' => 'Scheduled User']);
        $this->travelTo('2026-06-03 12:00:00');

        $this->actingAs($admin)
            ->postJson("/api/users/{$user->id}/retire", [
                'retirement_date' => '2026-06-30',
            ])
            ->assertOk()
            ->assertJsonPath('retirement_date', '2026-06-30')
            ->assertJsonPath('retired_at', '')
            ->assertJsonPath('is_retirement_scheduled', true);

        $this->assertNull($user->refresh()->retired_at);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records?month=2026-06&date=2026-06-03')
            ->assertOk()
            ->assertJsonPath('users.0.id', $user->id)
            ->assertJsonPath('users.0.is_retirement_scheduled', true)
            ->assertJsonPath('records.0.user_id', $user->id);

        $this->actingAs($admin)
            ->getJson("/api/retired-users?user_id={$user->id}")
            ->assertOk()
            ->assertJsonCount(0, 'users');
    }

    public function test_admin_can_cancel_scheduled_retirement(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'retirement_date' => '2026-06-30',
            'retired_at' => null,
        ]);
        $this->travelTo('2026-06-03 12:00:00');

        $this->actingAs($admin)
            ->postJson("/api/users/{$user->id}/restore")
            ->assertOk()
            ->assertJsonPath('retirement_date', '')
            ->assertJsonPath('is_retirement_scheduled', false);

        $user->refresh();
        $this->assertNull($user->retirement_date);
        $this->assertNull($user->retired_at);
    }

    public function test_past_retirement_date_moves_user_to_retired_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user', 'name' => 'Past Retired User']);
        $this->travelTo('2026-07-01 12:00:00');

        $this->actingAs($admin)
            ->postJson("/api/users/{$user->id}/retire", [
                'retirement_date' => '2026-06-30',
            ])
            ->assertOk()
            ->assertJsonPath('is_retirement_scheduled', false);

        $this->assertNotNull($user->refresh()->retired_at);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records?month=2026-06&date=2026-06-03')
            ->assertOk()
            ->assertJsonCount(0, 'users')
            ->assertJsonCount(0, 'records');

        $this->actingAs($admin)
            ->getJson("/api/retired-users?user_id={$user->id}")
            ->assertOk()
            ->assertJsonPath('users.0.id', $user->id);
    }
}
