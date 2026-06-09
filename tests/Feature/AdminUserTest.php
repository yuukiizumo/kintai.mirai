<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_can_view_admin_list(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'admin_level' => 'weak',
        ]);
        $strongAdmin = User::factory()->create([
            'role' => 'admin',
            'admin_level' => 'strong',
        ]);
        User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($admin)->getJson('/api/admins');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'admins')
            ->assertJsonFragment([
                'id' => $strongAdmin->id,
                'admin_level' => 'strong',
                'admin_level_label' => '強管理者',
            ]);
    }

    public function test_strong_admin_can_update_admin_level(): void
    {
        $strongAdmin = User::factory()->create([
            'role' => 'admin',
            'admin_level' => 'strong',
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'admin_level' => 'strong',
        ]);

        $this->actingAs($strongAdmin)
            ->patchJson("/api/admins/{$admin->id}", [
                'admin_level' => 'weak',
            ])
            ->assertOk()
            ->assertJsonPath('admin_level', 'weak');

        $this->assertSame('weak', $admin->refresh()->admin_level);
    }

    public function test_weak_admin_cannot_update_user_profile(): void
    {
        $weakAdmin = User::factory()->create([
            'role' => 'admin',
            'admin_level' => 'weak',
        ]);
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($weakAdmin)
            ->putJson("/api/users/{$user->id}/profile", [
                'name' => 'Updated User',
                'email' => $user->email,
            ])
            ->assertForbidden();
    }
}
