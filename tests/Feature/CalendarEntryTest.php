<?php

namespace Tests\Feature;

use App\Models\CalendarEntry;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_calendar_entries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'planned_vacation',
            'description' => 'test',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/calendar-entries?month=2026-06')
            ->assertOk()
            ->assertJsonPath('entries.0.type', 'planned_vacation')
            ->assertJsonPath('entries.0.type_label', '計画有給');
    }

    public function test_admin_can_create_calendar_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/calendar-entries', [
                'date' => '2026-06-10',
                'type' => 'holiday_off',
                'description' => '休業日',
            ])
            ->assertCreated()
            ->assertJsonPath('date', '2026-06-10')
            ->assertJsonPath('type', 'holiday_off')
            ->assertJsonPath('description', '休業日');

        $this->assertDatabaseHas('calendar_entries', [
            'date' => '2026-06-10',
            'type' => 'holiday_off',
            'description' => '休業日',
        ]);
    }

    public function test_regular_user_cannot_create_calendar_entry(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->postJson('/api/calendar-entries', [
                'date' => '2026-06-10',
                'type' => 'holiday_off',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_delete_calendar_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $entry = CalendarEntry::create([
            'date' => '2026-06-10',
            'type' => 'holiday_off',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/api/calendar-entries/{$entry->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('calendar_entries', [
            'id' => $entry->id,
        ]);
    }

    public function test_regular_user_cannot_delete_calendar_entry(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $entry = CalendarEntry::create([
            'date' => '2026-06-10',
            'type' => 'holiday_off',
        ]);

        $this->actingAs($user)
            ->deleteJson("/api/calendar-entries/{$entry->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('calendar_entries', [
            'id' => $entry->id,
        ]);
    }

    public function test_regular_user_can_view_monthly_saturday_work_and_holiday_off_highlights(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-06',
            'type' => 'saturday_work',
            'description' => '土曜開所',
        ]);
        CalendarEntry::create([
            'date' => '2026-06-15',
            'type' => 'holiday_off',
            'description' => '祝日',
        ]);
        CalendarEntry::create([
            'date' => '2026-07-04',
            'type' => 'saturday_work',
            'description' => '翌月',
        ]);

        $this->actingAs($user)
            ->getJson('/api/attendance-records?month=2026-06')
            ->assertOk()
            ->assertJsonPath('calendar_highlights.saturday_work.0.date', '2026-06-06')
            ->assertJsonPath('calendar_highlights.saturday_work.0.description', '土曜開所')
            ->assertJsonPath('calendar_highlights.holiday_off.0.date', '2026-06-15')
            ->assertJsonCount(1, 'calendar_highlights.saturday_work')
            ->assertJsonCount(1, 'calendar_highlights.holiday_off');
    }

    public function test_planned_vacation_is_displayed_as_paid_leave_for_working_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-8日',
        ]);
        CalendarEntry::create([
            'date' => '2026-06-01',
            'type' => 'planned_vacation',
        ]);

        $this->travelTo('2026-06-01 09:00:00');

        $this->actingAs($admin)
            ->getJson('/api/attendance-records?month=2026-06&date=2026-06-01')
            ->assertOk()
            ->assertJsonPath('records.0.display_status', '計画有給')
            ->assertJsonPath('records.0.display_status_type', 'planned_vacation')
            ->assertJsonPath('records.0.is_planned_vacation', true);
    }

    public function test_planned_vacation_on_saturday_is_not_displayed_as_holiday(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-05-30',
            'type' => 'planned_vacation',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-05')
            ->assertOk()
            ->assertJsonPath('records.1.display_status', '計画有給')
            ->assertJsonPath('records.1.display_status_type', 'planned_vacation')
            ->assertJsonPath('records.1.is_non_working_day', false)
            ->assertJsonPath('records.1.is_planned_vacation', true);

        $this->travelTo('2026-05-30 09:00:00');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertUnprocessable();
    }

    public function test_calendar_planned_vacation_does_not_adjust_remaining_days_when_the_day_comes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        CalendarEntry::create([
            'date' => '2026-06-10',
            'type' => 'planned_vacation',
        ]);

        $this->travelTo('2026-06-09 09:00:00');

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-06')
            ->assertOk();

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->travelTo('2026-06-10 09:00:00');

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-06')
            ->assertOk()
            ->assertJsonPath('records.20.display_status_type', 'planned_vacation');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_calendar_and_record_planned_vacation_do_not_adjust_remaining_days(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        CalendarEntry::create([
            'date' => '2026-06-10',
            'type' => 'planned_vacation',
        ]);
        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-10',
            'clock_in' => null,
            'clock_out' => null,
            'break_minutes' => 0,
            'status' => 'not_clocked',
        ]);

        $this->travelTo('2026-06-10 09:00:00');

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-06')
            ->assertOk();

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-10',
                'clock_in' => null,
                'clock_out' => null,
                'break_minutes' => 0,
                'status' => 'planned_vacation',
                'note' => null,
            ])
            ->assertOk();

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_saturday_work_allows_clock_in_on_saturday(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-06-06',
            'type' => 'saturday_work',
        ]);

        $this->travelTo('2026-06-06 09:00:00');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertOk()
            ->assertJsonPath('clock_in', '09:00');
    }

    public function test_free_attendance_day_allows_matching_user_to_clock_in(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-4日',
        ]);
        CalendarEntry::create([
            'date' => '2026-06-05',
            'type' => 'free_attendance_4',
        ]);

        $this->travelTo('2026-06-05 09:00:00');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertOk()
            ->assertJsonPath('clock_in', '09:00');
    }

    public function test_saturday_without_saturday_work_is_holiday_even_when_free_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-4日',
        ]);
        CalendarEntry::create([
            'date' => '2026-06-06',
            'type' => 'free_attendance_4',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-06')
            ->assertOk()
            ->assertJsonPath('records.24.display_status_type', 'holiday')
            ->assertJsonPath('records.24.is_non_working_day', true)
            ->assertJsonPath('records.24.break_minutes', 0);

        $this->travelTo('2026-06-06 09:00:00');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertUnprocessable();
    }

    public function test_free_attendance_day_allows_clock_in_even_when_calendar_holiday(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-4日',
        ]);
        CalendarEntry::create([
            'date' => '2026-05-04',
            'type' => 'holiday_off',
        ]);
        CalendarEntry::create([
            'date' => '2026-05-04',
            'type' => 'free_attendance_4',
        ]);

        $this->travelTo('2026-05-04 09:00:00');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertOk()
            ->assertJsonPath('clock_in', '09:00');
    }

    public function test_calendar_holiday_free_attendance_day_keeps_holiday_judgement_when_empty(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-4日',
        ]);
        CalendarEntry::create([
            'date' => '2026-05-04',
            'type' => 'holiday_off',
        ]);
        CalendarEntry::create([
            'date' => '2026-05-04',
            'type' => 'free_attendance_4',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-05')
            ->assertOk()
            ->assertJsonPath('records.27.display_status_type', 'free_attendance')
            ->assertJsonPath('records.27.is_non_working_day', true)
            ->assertJsonPath('records.27.break_minutes', 0);
    }

    public function test_free_attendance_day_is_displayed_even_when_clocked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-4日',
        ]);
        CalendarEntry::create([
            'date' => '2026-06-05',
            'type' => 'free_attendance_4',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-05',
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'break_minutes' => 0,
            'status' => 'completed',
        ]);

        $this->travelTo('2026-06-05 12:00:00');

        $this->actingAs($admin)
            ->getJson('/api/attendance-records?month=2026-06&date=2026-06-05&user_id='.$user->id)
            ->assertOk()
            ->assertJsonPath('records.0.display_status', '自由出勤日')
            ->assertJsonPath('records.0.display_status_type', 'free_attendance');
    }

    public function test_history_uses_calendar_settings_for_clocked_free_attendance_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'commute_limit_days' => '-4日',
        ]);
        CalendarEntry::create([
            'date' => '2026-05-04',
            'type' => 'holiday_off',
        ]);
        CalendarEntry::create([
            'date' => '2026-05-04',
            'type' => 'free_attendance_4',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-04',
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'break_minutes' => 0,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-05')
            ->assertOk()
            ->assertJsonPath('records.27.display_status_type', 'free_attendance')
            ->assertJsonPath('records.27.is_non_working_day', true);
    }

    public function test_saturday_work_overrides_stale_holiday_record_status_in_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        CalendarEntry::create([
            'date' => '2026-05-02',
            'type' => 'saturday_work',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-02',
            'clock_in' => null,
            'clock_out' => null,
            'break_minutes' => 0,
            'status' => 'holiday',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/attendance-records/history?user_id='.$user->id.'&month=2026-05')
            ->assertOk()
            ->assertJsonPath('records.29.status', 'not_clocked')
            ->assertJsonPath('records.29.display_status_type', 'not_clocked')
            ->assertJsonPath('records.29.is_non_working_day', false);
    }
}
