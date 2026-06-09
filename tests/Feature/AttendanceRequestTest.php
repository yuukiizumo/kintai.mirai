<?php

namespace Tests\Feature;

use App\Models\AttendanceRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_can_submit_attendance_request(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'late',
            'request_date' => '2026-06-01',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'reason_category' => '交通機関遅延のため',
            'reason' => '電車遅延のため',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('type', 'late')
            ->assertJsonPath('start_time', '10:00')
            ->assertJsonPath('end_time', '12:00')
            ->assertJsonPath('reason_category', '交通機関遅延のため')
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['created_at', 'submitted_at']);

        $this->assertDatabaseHas('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'late',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'reason_category' => '交通機関遅延のため',
            'status' => 'pending',
        ]);
    }

    public function test_regular_user_can_submit_added_attendance_request_types(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $changeResponse = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'change',
            'request_date' => '2026-06-01',
            'reason' => '予定変更',
        ]);

        $careServiceResponse = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'care_service',
            'request_date' => '2026-06-02',
            'start_time' => '13:00',
            'end_time' => '15:00',
            'reason' => '介護サービス利用',
        ]);

        $medicalResponse = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'off_hours_medical',
            'request_date' => '2026-06-03',
            'reason' => '勤務時間外通院',
        ]);

        $changeResponse
            ->assertCreated()
            ->assertJsonPath('type', 'change');

        $careServiceResponse
            ->assertCreated()
            ->assertJsonPath('type', 'care_service')
            ->assertJsonPath('start_time', '13:00')
            ->assertJsonPath('end_time', '15:00');

        $medicalResponse
            ->assertCreated()
            ->assertJsonPath('type', 'off_hours_medical');
    }

    public function test_regular_user_only_sees_own_requests(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);

        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'absence',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);
        AttendanceRequest::create([
            'user_id' => $other->id,
            'type' => 'overtime',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->getJson('/api/attendance-requests?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.user_id', $user->id);
    }

    public function test_admin_can_filter_requests_by_selected_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);

        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);
        AttendanceRequest::create([
            'user_id' => $other->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-02',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-requests?month=2026-06&user_id={$user->id}");

        $response
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.user_id', $user->id);
    }

    public function test_admin_can_see_all_requests_when_requested(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);

        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);
        AttendanceRequest::create([
            'user_id' => $other->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-02',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/attendance-requests?month=2026-06&all_users=1');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'requests');
    }

    public function test_request_lists_are_limited_to_twenty_for_regular_users(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        foreach (range(1, 25) as $day) {
            AttendanceRequest::create([
                'user_id' => $user->id,
                'type' => 'paid_leave',
                'request_date' => sprintf('2026-06-%02d', $day),
                'status' => 'pending',
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/attendance-requests?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonCount(20, 'requests')
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('requests.0.request_date', '2026-06-25')
            ->assertJsonPath('requests.19.request_date', '2026-06-06');

        $this->actingAs($user)
            ->getJson('/api/attendance-requests?month=2026-06&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'requests')
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('requests.0.request_date', '2026-06-05')
            ->assertJsonPath('requests.4.request_date', '2026-06-01');
    }

    public function test_request_lists_are_limited_to_twenty_for_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        foreach (range(1, 25) as $day) {
            AttendanceRequest::create([
                'user_id' => $user->id,
                'type' => 'paid_leave',
                'request_date' => sprintf('2026-06-%02d', $day),
                'status' => 'pending',
            ]);
        }

        $response = $this->actingAs($admin)->getJson('/api/attendance-requests?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonCount(20, 'requests')
            ->assertJsonPath('pagination.total', 25);
    }

    public function test_request_lists_can_be_filtered_by_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'late',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-02',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-requests?month=2026-06&all_users=1&type=late')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('requests.0.type', 'late');
    }

    public function test_admin_can_update_attendance_request_checks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $attendanceRequest = AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->patchJson("/api/attendance-requests/{$attendanceRequest->id}/checks", [
            'admin_checked' => true,
            'service_manager_checked' => false,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('admin_checked', true)
            ->assertJsonPath('service_manager_checked', false)
            ->assertJsonPath('status', 'admin_checked');

        $response = $this->actingAs($admin)->patchJson("/api/attendance-requests/{$attendanceRequest->id}/checks", [
            'admin_checked' => true,
            'service_manager_checked' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('admin_checked', true)
            ->assertJsonPath('service_manager_checked', true)
            ->assertJsonPath('status', 'service_manager_checked');
    }

    public function test_regular_user_cannot_update_attendance_request_checks(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $attendanceRequest = AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->patchJson("/api/attendance-requests/{$attendanceRequest->id}/checks", [
                'admin_checked' => true,
                'service_manager_checked' => false,
            ])
            ->assertForbidden();
    }

    public function test_regular_user_can_delete_own_attendance_request(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $attendanceRequest = AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/attendance-requests/{$attendanceRequest->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attendance_requests', [
            'id' => $attendanceRequest->id,
        ]);
    }

    public function test_regular_user_cannot_delete_another_users_attendance_request(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);
        $attendanceRequest = AttendanceRequest::create([
            'user_id' => $other->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/attendance-requests/{$attendanceRequest->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('attendance_requests', [
            'id' => $attendanceRequest->id,
        ]);
    }

    public function test_admin_can_delete_attendance_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $attendanceRequest = AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/attendance-requests/{$attendanceRequest->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attendance_requests', [
            'id' => $attendanceRequest->id,
        ]);
    }

    public function test_business_support_request_creates_fixed_attendance_record_and_delete_does_not_revert_it(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'business_support',
            'request_date' => '2026-06-01',
            'reason' => '業務対応',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('type', 'business_support')
            ->assertJsonPath('start_time', '10:00')
            ->assertJsonPath('end_time', '11:00');

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '11:00',
            'break_minutes' => 0,
            'declared_break_minutes' => 0,
            'status' => 'business_support',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/attendance-requests/{$response->json('id')}")
            ->assertNoContent();

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'status' => 'business_support',
        ]);
    }

    public function test_business_support_request_does_not_restore_existing_attendance_record_on_delete(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:15',
            'clock_out' => '17:45',
            'declared_clock_in' => '09:00',
            'declared_clock_out' => '18:00',
            'break_minutes' => 60,
            'declared_break_minutes' => 45,
            'status' => 'completed',
            'note' => '元の報告',
        ]);

        $response = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'business_support',
            'request_date' => '2026-06-01',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '11:00',
            'break_minutes' => 0,
            'declared_break_minutes' => 0,
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/attendance-requests/{$response->json('id')}")
            ->assertNoContent();

        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '11:00',
            'break_minutes' => 0,
            'declared_break_minutes' => 0,
            'status' => 'business_support',
        ]);
    }

    public function test_business_support_request_changes_attendance_display_status(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'business_support',
            'request_date' => '2026-06-01',
        ])->assertCreated();

        $response = $this->actingAs($user)->getJson('/api/attendance-records?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.display_status_type', 'business_support');
    }

    public function test_late_request_does_not_override_existing_attendance_display_status(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'late',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->getJson('/api/attendance-records?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.display_status_type', 'working')
            ->assertJsonPath('records.0.has_attendance_request', true);
    }

    public function test_admin_attendance_update_does_not_create_late_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-01',
                'clock_in' => '10:00',
                'clock_out' => '18:00',
                'break_minutes' => 60,
                'status' => 'late',
                'request_absent_start_time' => '09:00',
                'request_absent_end_time' => '10:00',
            ])
            ->assertOk()
            ->assertJsonPath('display_status_type', 'late')
            ->assertJsonPath('has_attendance_request', false);

        $this->assertDatabaseMissing('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'late',
            'request_date' => '2026-06-01',
        ]);
    }

    public function test_admin_attendance_update_does_not_create_late_and_early_leave_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '16:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-01',
                'clock_in' => '10:00',
                'clock_out' => '16:00',
                'break_minutes' => 60,
                'status' => 'late_and_early_leave',
                'request_late_start_time' => '09:00',
                'request_late_end_time' => '10:00',
                'request_early_leave_start_time' => '16:00',
                'request_early_leave_end_time' => '18:00',
            ])
            ->assertOk()
            ->assertJsonPath('display_status_type', 'late_and_early_leave')
            ->assertJsonPath('has_attendance_request', false);

        $this->assertDatabaseMissing('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'late',
            'request_date' => '2026-06-01',
        ]);
        $this->assertDatabaseMissing('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-01',
        ]);
    }

    public function test_early_leave_request_does_not_override_existing_attendance_display_status(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-02',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-02',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->getJson('/api/attendance-records?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.display_status_type', 'completed')
            ->assertJsonPath('records.1.display_status_type', 'working');
    }

    public function test_late_and_early_leave_requests_do_not_override_existing_attendance_display_status(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'late',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'early_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->getJson('/api/attendance-records?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.display_status_type', 'completed')
            ->assertJsonPath('records.0.attendance_request_types', ['late', 'early_leave']);
    }

    public function test_paid_leave_request_does_not_override_existing_attendance_display_status(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->getJson('/api/attendance-records?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.display_status_type', 'completed')
            ->assertJsonPath('records.0.has_attendance_request', true);
    }

    public function test_absence_request_marks_attendance_record_as_having_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-03 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-03',
            'break_minutes' => 0,
            'status' => 'absence',
        ]);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'absence',
            'request_date' => '2026-06-03',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records?month=2026-06&date=2026-06-03')
            ->assertOk()
            ->assertJsonPath('records.0.display_status_type', 'absence')
            ->assertJsonPath('records.0.has_attendance_request', true);
    }

    public function test_paid_leave_request_is_displayed_on_empty_history_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-records/history?user_id={$user->id}&month=2026-06");

        $response
            ->assertOk()
            ->assertJsonPath('records.29.work_date', '2026-06-01')
            ->assertJsonPath('records.29.display_status', '有給')
            ->assertJsonPath('records.29.display_status_type', 'paid_leave');
    }

    public function test_paid_leave_request_immediately_decreases_and_delete_restores_remaining_days(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
        ]);

        $response->assertCreated();
        $this->assertSame(9.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($user)
            ->deleteJson("/api/attendance-requests/{$response->json('id')}")
            ->assertNoContent();

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_half_paid_leave_request_immediately_decreases_and_delete_restores_remaining_days(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);

        $response = $this->actingAs($user)->postJson('/api/attendance-requests', [
            'type' => 'morning_paid_leave',
            'request_date' => '2026-06-01',
        ]);

        $response->assertCreated();
        $this->assertSame(9.5, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($user)
            ->deleteJson("/api/attendance-requests/{$response->json('id')}")
            ->assertNoContent();

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_admin_record_status_change_does_not_create_or_delete_paid_leave_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => null,
            'clock_out' => null,
            'break_minutes' => 0,
            'status' => 'not_clocked',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-01',
                'clock_in' => null,
                'clock_out' => null,
                'break_minutes' => 0,
                'status' => 'paid_leave',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('display_status_type', 'paid_leave');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
        $this->assertDatabaseMissing('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/attendance-requests', [
                'user_id' => $user->id,
                'type' => 'paid_leave',
                'request_date' => '2026-06-01',
            ])
            ->assertCreated();

        $this->assertSame(9.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-01',
                'clock_in' => null,
                'clock_out' => null,
                'break_minutes' => 0,
                'status' => 'not_clocked',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('display_status_type', 'not_clocked');

        $this->assertSame(9.0, (float) $user->refresh()->paid_leave_remaining_days);
        $this->assertDatabaseHas('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
        ]);
    }
}
