<?php

namespace Tests\Feature;

use App\Models\AdminMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_broadcast_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/admin-messages', [
            'body' => '明日は10時に集合してください。',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user_id', null)
            ->assertJsonPath('body', '明日は10時に集合してください。');

        $this->assertDatabaseHas('admin_messages', [
            'user_id' => null,
            'admin_id' => $admin->id,
            'body' => '明日は10時に集合してください。',
        ]);
    }

    public function test_regular_user_can_see_broadcast_messages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        AdminMessage::create([
            'user_id' => null,
            'admin_id' => $admin->id,
            'body' => '全員宛です。',
        ]);
        AdminMessage::create([
            'user_id' => $user->id,
            'admin_id' => $admin->id,
            'body' => '古い個別宛です。',
        ]);

        $response = $this->actingAs($user)->getJson('/api/admin-messages');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.body', '全員宛です。');
    }

    public function test_messages_older_than_three_days_are_marked_collapsed_by_default(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-05 12:00:00');

        $recentMessage = AdminMessage::create([
            'user_id' => null,
            'admin_id' => $admin->id,
            'body' => 'recent',
        ]);
        $oldMessage = AdminMessage::create([
            'user_id' => null,
            'admin_id' => $admin->id,
            'body' => 'old',
        ]);
        $oldMessage->forceFill(['created_at' => now()->subDays(4)])->save();

        $response = $this->actingAs($user)->getJson('/api/admin-messages');

        $response
            ->assertOk()
            ->assertJsonPath('messages.0.id', $recentMessage->id)
            ->assertJsonPath('messages.0.is_collapsed_default', false)
            ->assertJsonPath('messages.1.id', $oldMessage->id)
            ->assertJsonPath('messages.1.is_collapsed_default', true);
    }

    public function test_regular_user_cannot_send_admin_messages(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->postJson('/api/admin-messages', [
            'body' => '送信できないメッセージ',
        ])->assertForbidden();
    }
}
