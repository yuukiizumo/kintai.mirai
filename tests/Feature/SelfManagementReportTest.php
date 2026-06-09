<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\SelfManagementReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfManagementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_submit_self_management_report_during_active_week(): void
    {
        $this->travelTo('2026-06-05 10:00:00');
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'self_report_due',
        ]);

        $response = $this->actingAs($user)->postJson('/api/self-management-reports', [
            'report_date' => '2026-06-01',
            'work_rating' => '良好',
            'life_rating' => '安定',
            'monthly_reflection' => '今月も作業に集中できました。',
            'next_month_goal' => '作業速度を上げます。',
            'skill_progress' => '新しい作業を覚えました。',
            'activity_status' => '継続中',
            'activity_detail' => '毎日記録しました。',
            'other' => '特になし',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('report_date', '2026-06-01')
            ->assertJsonPath('work_rating', '良好');

        $this->assertDatabaseHas('self_management_reports', [
            'user_id' => $user->id,
            'report_date' => '2026-06-01',
            'work_rating' => '良好',
        ]);
    }

    public function test_user_cannot_submit_self_management_report_outside_active_week(): void
    {
        $this->travelTo('2026-06-09 10:00:00');
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'self_report_due',
        ]);

        $response = $this->actingAs($user)->postJson('/api/self-management-reports', [
            'report_date' => '2026-06-01',
            'work_rating' => '良好',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseMissing('self_management_reports', [
            'user_id' => $user->id,
            'report_date' => '2026-06-01',
        ]);
    }

    public function test_admin_can_see_all_users_self_management_reports(): void
    {
        $this->travelTo('2026-06-05 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $submittedUser = User::factory()->create(['role' => 'user', 'name' => '提出 太郎']);
        $pendingUser = User::factory()->create(['role' => 'user', 'name' => '未提出 花子']);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'self_report_due',
        ]);
        SelfManagementReport::create([
            'user_id' => $submittedUser->id,
            'report_date' => '2026-06-01',
            'work_rating' => '良好',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/self-management-reports');

        $response
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('selected_report_date', '2026-06-01')
            ->assertJsonCount(2, 'reports')
            ->assertJsonPath('reports.0.submitted', true)
            ->assertJsonPath('reports.1.submitted', false);

        $this->assertSame($pendingUser->id, $response->json('reports.1.user_id'));
    }

    public function test_admin_can_see_latest_self_management_report_outside_active_week(): void
    {
        $this->travelTo('2026-06-20 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'self_report_due',
        ]);
        SelfManagementReport::create([
            'user_id' => $user->id,
            'report_date' => '2026-06-01',
            'work_rating' => '良好',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/self-management-reports');

        $response
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('selected_report_date', '2026-06-01')
            ->assertJsonPath('reports.0.submitted', true)
            ->assertJsonPath('reports.0.work_rating', '良好');
    }

    public function test_regular_user_does_not_see_form_outside_active_week(): void
    {
        $this->travelTo('2026-06-20 10:00:00');
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'self_report_due',
        ]);

        $response = $this->actingAs($user)->getJson('/api/self-management-reports');

        $response
            ->assertOk()
            ->assertJsonPath('active', false)
            ->assertJsonPath('selected_report_date', null);
    }

    public function test_admin_can_update_self_management_report_comment(): void
    {
        $this->travelTo('2026-06-05 10:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $report = SelfManagementReport::create([
            'user_id' => $user->id,
            'report_date' => '2026-06-01',
            'work_rating' => '良好',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/self-management-reports/{$report->id}", [
            'admin_comment' => 'よく振り返りできています。',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('admin_comment', 'よく振り返りできています。');

        $this->assertDatabaseHas('self_management_reports', [
            'id' => $report->id,
            'admin_comment' => 'よく振り返りできています。',
        ]);
    }

    public function test_self_management_report_generates_admin_comment_once(): void
    {
        $this->travelTo('2026-06-05 10:00:00');
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-05',
            'type' => 'self_report_due',
        ]);

        $response = $this->actingAs($user)->postJson('/api/self-management-reports', [
            'report_date' => '2026-06-05',
            'work_rating' => '毎日頑張れている',
            'life_rating' => 'まずまず頑張れている',
            'skill_progress' => 'はい',
            'activity_status' => 'はい',
            'monthly_reflection' => '今月も継続して取り組みました。',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('admin_comment', '日々の取り組みがしっかり継続できている様子が伝わります。この調子で、できていることを積み重ねていきましょう。');

        $report = SelfManagementReport::firstOrFail();
        $report->forceFill([
            'monthly_reflection' => '内容を変更しました。',
        ])->save();

        $secondResponse = $this->actingAs($user)->getJson('/api/self-management-reports');

        $secondResponse
            ->assertOk()
            ->assertJsonPath('report.admin_comment', '日々の取り組みがしっかり継続できている様子が伝わります。この調子で、できていることを積み重ねていきましょう。');
    }
}
