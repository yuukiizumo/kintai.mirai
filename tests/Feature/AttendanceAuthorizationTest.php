<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_login_rejects_admin_accounts(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_admin_login_rejects_user_accounts(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('password'),
        ]);

        $response = $this->post('/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_login_redirects_home_even_when_intended_url_is_api(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('password'),
        ]);

        $this->withSession(['url.intended' => '/api/attendance-records?month=2026-06'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect('/');
    }

    public function test_regular_users_only_receive_their_own_attendance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        $record = AttendanceRecord::create([
            'user_id' => $other->id,
            'work_date' => '2026-05-01',
            'clock_in' => '10:00',
            'clock_out' => '19:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->getJson("/api/attendance-records?month=2026-05&user_id={$other->id}");

        $response
            ->assertOk()
            ->assertJsonPath('viewer.is_admin', false)
            ->assertJsonPath('selected_user_id', $user->id)
            ->assertJsonCount(1, 'users')
            ->assertJsonCount(1, 'records')
            ->assertJsonPath('records.0.user_id', $user->id);
    }

    public function test_regular_user_attendance_records_are_ordered_by_newest_date_first(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-03',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->getJson('/api/attendance-records?month=2026-05')
            ->assertOk()
            ->assertJsonPath('records.0.work_date', '2026-05-03')
            ->assertJsonPath('records.1.work_date', '2026-05-01');
    }

    public function test_admins_can_receive_all_users_today_attendance(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);
        $notClockedUser = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-01 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRecord::create([
            'user_id' => $other->id,
            'work_date' => '2026-05-01',
            'clock_in' => '10:00',
            'clock_out' => '19:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-records?month=2026-05&user_id={$user->id}");

        $response
            ->assertOk()
            ->assertJsonPath('viewer.is_admin', true)
            ->assertJsonCount(3, 'records')
            ->assertJsonPath('records.0.user_id', $user->id)
            ->assertJsonPath('records.1.user_id', $other->id)
            ->assertJsonPath('records.2.user_id', $notClockedUser->id)
            ->assertJsonPath('records.2.status', 'not_clocked')
            ->assertJsonPath('records.2.clock_in', '')
            ->assertJsonPath('records.2.clock_out', '');
    }

    public function test_admin_can_update_display_order_and_attendance_uses_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = User::factory()->create(['role' => 'user', 'department' => '譁ｰ莉雁ｮｮ', 'display_order' => 10]);
        $second = User::factory()->create(['role' => 'user', 'department' => '譁ｰ莉雁ｮｮ', 'display_order' => 20]);
        $third = User::factory()->create(['role' => 'user', 'department' => '譁ｰ莉雁ｮｮ', 'display_order' => 1]);
        $this->travelTo('2026-06-03 12:00:00');

        $this->actingAs($admin)
            ->putJson('/api/users/display-order', [
                'orders' => [
                    ['user_id' => $first->id, 'display_order' => 30, 'department' => '譁ｰ莉雁ｮｮ'],
                    ['user_id' => $second->id, 'display_order' => 5, 'department' => '譁ｰ莉雁ｮｮ'],
                    ['user_id' => $third->id, 'display_order' => 1, 'department' => '譁ｰ莉雁ｮｮ'],
                ],
            ])
            ->assertOk();

        $response = $this->actingAs($admin)->getJson('/api/attendance-records?month=2026-06&date=2026-06-03');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.user_id', $third->id)
            ->assertJsonPath('records.1.user_id', $second->id)
            ->assertJsonPath('records.2.user_id', $first->id);
    }

    public function test_admin_can_update_display_order_across_departments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user', 'department' => '譁ｰ莉雁ｮｮ', 'display_order' => 10]);

        $this->actingAs($admin)
            ->putJson('/api/users/display-order', [
                'orders' => [
                    ['user_id' => $user->id, 'display_order' => 10, 'department' => '日本橋'],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'department' => '日本橋',
            'display_order' => 10,
        ]);
    }

    public function test_admin_can_update_department_display_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $firstDepartmentUser = User::factory()->create([
            'role' => 'user',
            'department' => 'A',
            'display_order' => 10,
            'department_display_order' => 10,
        ]);
        $secondDepartmentUser = User::factory()->create([
            'role' => 'user',
            'department' => 'B',
            'display_order' => 10,
            'department_display_order' => 20,
        ]);
        $this->travelTo('2026-06-03 12:00:00');

        $this->actingAs($admin)
            ->putJson('/api/users/display-order', [
                'orders' => [
                    [
                        'user_id' => $firstDepartmentUser->id,
                        'display_order' => 10,
                        'department_display_order' => 20,
                        'department' => 'A',
                    ],
                    [
                        'user_id' => $secondDepartmentUser->id,
                        'display_order' => 10,
                        'department_display_order' => 10,
                        'department' => 'B',
                    ],
                ],
            ])
            ->assertOk();

        $response = $this->actingAs($admin)->getJson('/api/attendance-records?month=2026-06&date=2026-06-03');

        $response
            ->assertOk()
            ->assertJsonPath('records.0.user_id', $secondDepartmentUser->id)
            ->assertJsonPath('records.1.user_id', $firstDepartmentUser->id)
            ->assertJsonPath('users.0.department_display_order', 10)
            ->assertJsonPath('users.1.department_display_order', 20);
    }

    public function test_admins_can_receive_all_users_attendance_for_selected_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-02 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '08:30',
            'clock_out' => '17:30',
            'break_minutes' => 45,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/attendance-records?month=2026-06&date=2026-06-01');

        $response
            ->assertOk()
            ->assertJsonPath('display_date', '2026-06-01')
            ->assertJsonPath('records.0.work_date', '2026-06-01')
            ->assertJsonPath('records.0.clock_in', '08:30');
    }

    public function test_admins_can_receive_monthly_attendance_history_for_one_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-15',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-04-30',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRecord::create([
            'user_id' => $other->id,
            'work_date' => '2026-05-15',
            'clock_in' => '10:00',
            'clock_out' => '19:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-records/history?user_id={$user->id}&month=2026-05");

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('month', '2026-05')
            ->assertJsonPath('start_date', '2026-05-01')
            ->assertJsonPath('end_date', '2026-05-31')
            ->assertJsonCount(31, 'records')
            ->assertJsonPath('records.0.work_date', '2026-05-31')
            ->assertJsonPath('records.0.clock_in', '')
            ->assertJsonPath('records.0.status', 'holiday')
            ->assertJsonPath('records.0.break_minutes', 0)
            ->assertJsonPath('records.0.worked_minutes', 0)
            ->assertJsonPath('records.0.is_non_working_day', true)
            ->assertJsonPath('records.1.work_date', '2026-05-30')
            ->assertJsonPath('records.1.status', 'holiday')
            ->assertJsonPath('records.1.is_non_working_day', true)
            ->assertJsonPath('records.27.work_date', '2026-05-04')
            ->assertJsonPath('records.27.status', 'holiday')
            ->assertJsonPath('records.24.work_date', '2026-05-07')
            ->assertJsonPath('records.24.status', '')
            ->assertJsonPath('records.16.work_date', '2026-05-15')
            ->assertJsonPath('records.16.user_id', $user->id)
            ->assertJsonPath('records.16.clock_in', '09:00');
    }

    public function test_admins_can_receive_monthly_attendance_history_for_department_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = User::factory()->create(['role' => 'user', 'department' => '譁ｰ莉雁ｮｮ']);
        $second = User::factory()->create(['role' => 'user', 'department' => '譁ｰ莉雁ｮｮ']);
        $other = User::factory()->create(['role' => 'user', 'department' => '日本橋']);

        AttendanceRecord::create([
            'user_id' => $first->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);
        AttendanceRecord::create([
            'user_id' => $other->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/attendance-records/history?department='.urlencode('譁ｰ莉雁ｮｮ').'&month=2026-06');

        $response
            ->assertOk()
            ->assertJsonPath('mode', 'department')
            ->assertJsonPath('department', '譁ｰ莉雁ｮｮ')
            ->assertJsonCount(60, 'records');

        $userIds = collect($response->json('records'))->pluck('user_id')->unique()->sort()->values()->all();

        $this->assertSame([$first->id, $second->id], $userIds);
    }

    public function test_department_monthly_attendance_history_is_paginated_by_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $users = collect(range(1, 16))->map(fn (int $index) => User::factory()->create([
            'role' => 'user',
            'department' => 'Dept A',
            'display_order' => $index,
        ]));

        $firstPage = $this->actingAs($admin)->getJson('/api/attendance-records/history?department=Dept+A&month=2026-06&page=1&per_page=15');
        $secondPage = $this->actingAs($admin)->getJson('/api/attendance-records/history?department=Dept+A&month=2026-06&page=2&per_page=15');

        $firstPage
            ->assertOk()
            ->assertJsonPath('pagination.page', 1)
            ->assertJsonPath('pagination.per_page', 15)
            ->assertJsonPath('pagination.total_users', 16)
            ->assertJsonCount(15, 'users')
            ->assertJsonCount(450, 'records');

        $secondPage
            ->assertOk()
            ->assertJsonPath('pagination.page', 2)
            ->assertJsonPath('pagination.per_page', 15)
            ->assertJsonPath('pagination.total_users', 16)
            ->assertJsonCount(1, 'users')
            ->assertJsonCount(30, 'records');

        $this->assertSame($users->last()->id, $secondPage->json('users.0.id'));
    }

    public function test_admin_can_download_monthly_attendance_history_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'name' => 'Pdf User',
            'management_number' => 'B81',
            'default_clock_in' => '10:00',
            'default_clock_out' => '15:00',
            'default_break_minutes' => 60,
        ]);

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:30',
            'clock_out' => '15:30',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '15:00',
            'declared_break_minutes' => 60,
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get("/api/attendance-records/history/pdf?user_id={$user->id}&month=2026-06");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_monthly_attendance_history_pdf_can_be_generated_when_declared_times_are_blank(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'default_clock_in' => '10:00',
            'default_clock_out' => '15:00',
            'default_break_minutes' => 60,
        ]);

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '15:00',
            'declared_clock_in' => null,
            'declared_clock_out' => null,
            'declared_break_minutes' => null,
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->get("/api/attendance-records/history/pdf?user_id={$user->id}&month=2026-06");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_admin_can_download_company_storage_monthly_attendance_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'department' => '在宅',
            'work_style' => 'B型',
            'height_cm' => 170,
            'weight_kg' => 60,
            'default_clock_in' => '10:00',
            'default_clock_out' => '15:00',
            'default_break_minutes' => 60,
        ]);

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '10:00',
            'clock_out' => '15:00',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '15:00',
            'declared_break_minutes' => 60,
            'work_location' => 'home',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => 'report',
            'admin_comment' => 'comment',
        ]);

        $response = $this->actingAs($admin)->get("/api/attendance-records/history/company-pdf?user_id={$user->id}&month=2026-06");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_admin_can_download_monthly_business_report_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => 'report',
            'admin_comment' => 'comment',
        ]);

        $response = $this->actingAs($admin)->get("/api/attendance-records/business-reports/pdf?user_id={$user->id}&month=2026-06");

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_attendance_records_include_admin_comment_for_business_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => '軽作業を丁寧に進めました。',
        ]);

        $response = $this->actingAs($admin)
            ->getJson("/api/attendance-records/history?user_id={$user->id}&month=2026-06")
            ->assertOk()
            ->assertJsonPath('records.29.note', '軽作業を丁寧に進めました。');

        $this->assertNotSame('', $response->json('records.29.admin_comment'));
        $this->assertNotSame('', $record->refresh()->admin_comment);
    }

    public function test_admin_can_update_attendance_admin_comment(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-04 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-04',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => '作業しました。',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-04',
                'clock_in' => '09:00',
                'clock_out' => '15:00',
                'break_minutes' => 60,
                'status' => 'completed',
                'note' => '作業しました。',
                'admin_comment' => '丁寧に取り組めています。',
            ])
            ->assertOk()
            ->assertJsonPath('admin_comment', '丁寧に取り組めています。');

        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'admin_comment' => '丁寧に取り組めています。',
        ]);
    }

    public function test_regular_user_cannot_update_attendance_admin_comment(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-04 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-04',
            'clock_in' => '09:00',
            'clock_out' => '15:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => '作業しました。',
            'admin_comment' => '管理者のコメント',
        ]);

        $this->actingAs($user)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-06-04',
                'clock_in' => '09:00',
                'clock_out' => '15:00',
                'break_minutes' => 60,
                'status' => 'completed',
                'note' => '作業しました。',
                'admin_comment' => 'ユーザーが上書き',
            ])
            ->assertOk();

        $this->assertSame('管理者のコメント', $record->refresh()->admin_comment);
    }

    public function test_regular_users_cannot_receive_past_month_attendance_history(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->getJson('/api/attendance-records/history')
            ->assertForbidden();
    }

    public function test_admin_can_receive_business_report_summaries(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user', 'name' => '佐藤 花子']);
        $other = User::factory()->create(['role' => 'user', 'name' => '田中 太郎']);
        $this->travelTo('2026-06-01 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '顧客対応を行いました。',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-02',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '入力作業を整理しました。',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-records/business-reports?user_id={$user->id}&month=2026-06");

        $response
            ->assertOk()
            ->assertJsonPath('today_reports.0.employee', '佐藤 花子')
            ->assertJsonPath('today_reports.0.note', '顧客対応を行いました。')
            ->assertJsonPath('today_reports.1.employee', '田中 太郎')
            ->assertJsonPath('monthly_reports.0.work_date', '2026-06-02')
            ->assertJsonPath('monthly_reports.1.work_date', '2026-06-01');
    }

    public function test_admin_can_receive_business_report_summaries_for_selected_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-02 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '6月1日の業務報告',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-records/business-reports?user_id={$user->id}&month=2026-06&date=2026-06-01");

        $response
            ->assertOk()
            ->assertJsonPath('display_date', '2026-06-01')
            ->assertJsonPath('today_reports.0.work_date', '2026-06-01')
            ->assertJsonPath('today_reports.0.note', '6月1日の業務報告');
    }

    public function test_regular_users_cannot_receive_business_report_summaries(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user)
            ->getJson("/api/attendance-records/business-reports?user_id={$user->id}&month=2026-06")
            ->assertForbidden();
    }

    public function test_clocked_out_working_records_are_displayed_as_completed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 12:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-29',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);

        $response = $this->actingAs($admin)->getJson("/api/attendance-records/history?user_id={$user->id}&month=2026-05");

        $response
            ->assertOk()
            ->assertJsonPath('records.2.work_date', '2026-05-29')
            ->assertJsonPath('records.2.status', 'completed')
            ->assertJsonPath('records.2.display_status_type', 'completed');
    }

    public function test_regular_users_cannot_modify_another_users_attendance(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);

        $record = AttendanceRecord::create([
            'user_id' => $other->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->deleteJson("/api/attendance-records/{$record->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('attendance_records', ['id' => $record->id]);
    }

    public function test_attendance_older_than_three_days_cannot_be_updated(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-26',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->putJson("/api/attendance-records/{$record->id}", [
            'user_id' => $user->id,
            'work_date' => '2026-05-26',
            'clock_in' => '10:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => null,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $record->id,
            'clock_in' => '09:00',
        ]);
    }

    public function test_admin_can_update_attendance_older_than_three_days(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->putJson("/api/attendance-records/{$record->id}", [
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '10:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('clock_in', '10:00')
            ->assertJsonPath('can_edit', true);
    }

    public function test_admin_can_update_declared_work_times(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)->putJson("/api/attendance-records/{$record->id}", [
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '16:00',
            'declared_break_minutes' => 45,
            'work_location' => 'office',
            'meal_percentage' => 80,
            'missed_meal' => true,
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('declared_clock_in', '10:00')
            ->assertJsonPath('declared_clock_out', '16:00')
            ->assertJsonPath('declared_break_minutes', 45)
            ->assertJsonPath('work_location', 'office')
            ->assertJsonPath('meal_percentage', 80)
            ->assertJsonPath('missed_meal', true);
    }

    public function test_admin_attendance_update_to_exceptional_status_does_not_create_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '10:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => '10:00',
                'clock_out' => '18:00',
                'break_minutes' => 60,
                'status' => 'late',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'late');
        $this->assertDatabaseMissing('attendance_requests', [
            'user_id' => $user->id,
            'type' => 'late',
            'request_date' => '2026-05-01',
        ]);

        $this->actingAs($admin)
            ->getJson("/api/attendance-records/history?user_id={$user->id}&month=2026-05")
            ->assertOk()
            ->assertJsonPath('records.30.status', 'late')
            ->assertJsonPath('records.30.request_reason_category', '')
            ->assertJsonPath('records.30.request_reason', '');
    }

    public function test_attendance_update_to_paid_leave_does_not_adjust_remaining_days_without_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => null,
                'clock_out' => null,
                'break_minutes' => 0,
                'status' => 'paid_leave',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'paid_leave');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => '09:00',
                'clock_out' => '18:00',
                'break_minutes' => 60,
                'status' => 'completed',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_attendance_update_to_planned_vacation_does_not_adjust_remaining_days(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => null,
            'clock_out' => null,
            'break_minutes' => 0,
            'status' => 'not_clocked',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => null,
                'clock_out' => null,
                'break_minutes' => 0,
                'status' => 'planned_vacation',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'planned_vacation')
            ->assertJsonPath('display_status_type', 'planned_vacation');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => null,
                'clock_out' => null,
                'break_minutes' => 0,
                'status' => 'not_clocked',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'not_clocked');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_future_attendance_update_to_planned_vacation_does_not_adjust_remaining_days_on_due_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        $this->travelTo('2026-06-01 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-10',
            'clock_in' => null,
            'clock_out' => null,
            'break_minutes' => 0,
            'status' => 'not_clocked',
        ]);

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
            ->assertOk()
            ->assertJsonPath('status', 'planned_vacation');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->travelTo('2026-06-10 09:00:00');

        $this->actingAs($admin)
            ->getJson("/api/attendance-records/history?user_id={$user->id}&month=2026-06")
            ->assertOk();

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_attendance_update_to_half_paid_leave_does_not_adjust_remaining_days_without_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'paid_leave_remaining_days' => 10,
        ]);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '13:00',
            'break_minutes' => 0,
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => '09:00',
                'clock_out' => '13:00',
                'break_minutes' => 0,
                'status' => 'morning_paid_leave',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'morning_paid_leave');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);

        $this->actingAs($admin)
            ->putJson("/api/attendance-records/{$record->id}", [
                'user_id' => $user->id,
                'work_date' => '2026-05-01',
                'clock_in' => '09:00',
                'clock_out' => '13:00',
                'break_minutes' => 0,
                'status' => 'completed',
                'note' => null,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertSame(10.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_admin_can_update_attendance_to_exceptional_statuses_without_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        foreach (['late', 'early_leave', 'late_and_early_leave', 'business_support'] as $status) {
            $this->actingAs($admin)
                ->putJson("/api/attendance-records/{$record->id}", [
                    'user_id' => $user->id,
                    'work_date' => '2026-05-01',
                    'clock_in' => '09:00',
                    'clock_out' => '18:00',
                    'break_minutes' => 60,
                    'status' => $status,
                    'note' => null,
                ])
                ->assertOk()
                ->assertJsonPath('status', $status)
                ->assertJsonPath('display_status_type', $status)
                ->assertJsonPath('has_attendance_request', false);
        }
    }

    public function test_recent_attendance_can_be_updated(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-05-29 10:00:00');

        $record = AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-27',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->putJson("/api/attendance-records/{$record->id}", [
            'user_id' => $user->id,
            'work_date' => '2026-05-27',
            'clock_in' => '10:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
            'note' => null,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('clock_in', '10:00')
            ->assertJsonPath('can_edit', true);
    }

    public function test_admin_can_update_user_work_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($admin)->putJson("/api/users/{$user->id}/work-settings", [
            'workday_settings' => $this->workdaySettings('08:30', '17:30', 45),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('default_clock_in', '08:30')
            ->assertJsonPath('default_clock_out', '17:30')
            ->assertJsonPath('default_break_minutes', 45)
            ->assertJsonPath('workday_settings.1.default_break_minutes', 45);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'default_break_minutes' => 45,
        ]);
    }

    public function test_regular_user_cannot_update_work_settings(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->putJson("/api/users/{$user->id}/work-settings", [
            'workday_settings' => $this->workdaySettings('08:30', '17:30', 45),
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_update_user_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($admin)->putJson("/api/users/{$user->id}/profile", [
            'name' => '山田 太郎',
            'hire_date' => '2026-04-01',
            'management_number' => 'EMP-001',
            'email' => 'taro@example.com',
            'workday_settings' => $this->workdaySettings('08:45', '17:15', 60),
            'hourly_wage' => 1200,
            'department' => '新今宮',
            'business_category' => '軽作業',
            'work_style' => 'A型',
            'commute_limit_days' => '-8日',
            'height_cm' => 170.5,
            'weight_kg' => 65.2,
            'gender' => '男',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', '山田 太郎')
            ->assertJsonPath('management_number', 'EMP-001')
            ->assertJsonPath('email', 'taro@example.com')
            ->assertJsonPath('default_clock_in', '08:45')
            ->assertJsonPath('default_clock_out', '17:15')
            ->assertJsonPath('workday_settings.1.default_clock_in', '08:45');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => '山田 太郎',
            'management_number' => 'EMP-001',
            'email' => 'taro@example.com',
            'default_clock_in' => '08:45',
            'default_clock_out' => '17:15',
            'hourly_wage' => 1200,
            'department' => '新今宮',
            'business_category' => '軽作業',
            'work_style' => 'A型',
            'commute_limit_days' => '-8日',
            'gender' => '男',
        ]);
    }

    public function test_regular_user_cannot_update_user_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $response = $this->actingAs($user)->putJson("/api/users/{$user->id}/profile", [
            'name' => '山田 太郎',
            'email' => 'taro@example.com',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_save_profile_with_non_working_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $workdaySettings = $this->workdaySettings('09:00', '18:00', 60);
        $workdaySettings['6']['is_working_day'] = false;

        $response = $this->actingAs($admin)->putJson("/api/users/{$user->id}/profile", [
            'name' => $user->name,
            'email' => $user->email,
            'workday_settings' => $workdaySettings,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('workday_settings.6.is_working_day', false);
    }

    public function test_admin_can_update_user_profile_with_blank_optional_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create([
            'role' => 'user',
            'name' => '譌｢蟄倥Θ繝ｼ繧ｶ繝ｼ',
            'email' => 'existing@example.com',
            'workday_settings' => $this->workdaySettings('09:00', '18:00', 60),
        ]);
        $blankWorkdaySettings = collect(range(1, 6))
            ->mapWithKeys(fn (int $weekday) => [
                (string) $weekday => [
                    'default_clock_in' => '',
                    'default_clock_out' => '',
                    'default_break_minutes' => '',
                ],
            ])
            ->all();

        $response = $this->actingAs($admin)->putJson("/api/users/{$user->id}/profile", [
            'name' => '',
            'hire_date' => '',
            'management_number' => '',
            'email' => '',
            'workday_settings' => $blankWorkdaySettings,
            'hourly_wage' => null,
            'department' => '',
            'business_category' => '',
            'work_style' => '',
            'commute_limit_days' => '',
            'height_cm' => null,
            'weight_kg' => null,
            'gender' => '',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('name', '譌｢蟄倥Θ繝ｼ繧ｶ繝ｼ')
            ->assertJsonPath('email', 'existing@example.com')
            ->assertJsonPath('workday_settings.1.default_clock_in', '09:00');
    }

    public function test_admin_profile_update_calculates_paid_leave_remaining_days_from_hire_date(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 09:00:00');

        $response = $this->actingAs($admin)->putJson("/api/users/{$user->id}/profile", [
            'name' => '有給 太郎',
            'hire_date' => '2023-01-01',
            'management_number' => '',
            'email' => 'paid-leave@example.com',
            'workday_settings' => $this->workdaySettings('09:00', '18:00', 60),
            'hourly_wage' => null,
            'department' => '',
            'business_category' => '',
            'work_style' => '',
            'commute_limit_days' => '',
            'paid_leave_remaining_days' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'gender' => '',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('paid_leave_remaining_days', '23.0');

        $this->assertSame(23.0, (float) $user->refresh()->paid_leave_remaining_days);
    }

    public function test_clock_uses_user_default_break_minutes(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'default_break_minutes' => 45,
            'workday_settings' => $this->workdaySettings('09:00', '18:00', 30),
        ]);

        $this->travelTo('2026-06-01 09:00:00');

        $response = $this->actingAs($user)->postJson('/api/attendance-records/clock', [
            'user_id' => $user->id,
            'type' => 'in',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('break_minutes', 30);
    }

    public function test_clock_out_stores_declared_work_times(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 18:00:00');
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '本日の業務報告です。',
        ]);

        $response = $this->actingAs($user)->postJson('/api/attendance-records/clock', [
            'user_id' => $user->id,
            'type' => 'out',
            'declared_clock_in' => '09:30',
            'declared_clock_out' => '17:30',
            'declared_break_minutes' => 30,
            'work_location' => 'office',
            'meal_percentage' => 75,
            'missed_meal' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('declared_clock_in', '09:30')
            ->assertJsonPath('declared_clock_out', '17:30')
            ->assertJsonPath('declared_break_minutes', 30)
            ->assertJsonPath('work_location', 'office')
            ->assertJsonPath('meal_percentage', 75)
            ->assertJsonPath('missed_meal', true)
            ->assertJsonPath('status', 'completed');
    }

    public function test_clock_out_is_rejected_when_not_clocked_in(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 18:00:00');

        $this->actingAs($user)
            ->getJson('/api/attendance-records?month=2026-06')
            ->assertOk()
            ->assertJsonPath('clock.can_clock_out', false);

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'out',
            ])
            ->assertStatus(422);
    }

    public function test_clock_out_is_rejected_when_business_report_is_blank(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 18:00:00');
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '',
        ]);

        $this->actingAs($user)
            ->getJson('/api/attendance-records?month=2026-06')
            ->assertOk()
            ->assertJsonPath('clock.can_clock_out', false)
            ->assertJsonPath('clock.clock_out_disabled_reason', '業務報告を入力してから退勤してください。');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'out',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_home_work_location_clears_meal_fields(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 18:00:00');
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '本日の業務報告です。',
        ]);

        $this->actingAs($user)->postJson('/api/attendance-records/clock', [
            'user_id' => $user->id,
            'type' => 'out',
            'work_location' => 'home',
            'meal_percentage' => 90,
            'missed_meal' => true,
        ])
            ->assertOk()
            ->assertJsonPath('work_location', 'home')
            ->assertJsonPath('meal_percentage', null)
            ->assertJsonPath('missed_meal', false);
    }

    public function test_user_can_cancel_recent_clock_in(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 09:00:00');

        $this->actingAs($user)->postJson('/api/attendance-records/clock', [
            'user_id' => $user->id,
            'type' => 'in',
        ])->assertOk();

        $this->travelTo('2026-06-01 09:04:00');

        $this->actingAs($user)->postJson('/api/attendance-records/clock/cancel', [
            'user_id' => $user->id,
            'type' => 'in',
        ])->assertOk()
            ->assertJsonPath('cancelled', true)
            ->assertJsonPath('deleted', true);

        $this->assertDatabaseMissing('attendance_records', [
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
        ]);
    }

    public function test_user_can_cancel_recent_clock_out(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 18:00:00');
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_in_recorded_at' => '2026-06-01 09:00:00',
            'break_minutes' => 60,
            'status' => 'working',
            'note' => '本日の業務報告です。',
        ]);

        $this->actingAs($user)->postJson('/api/attendance-records/clock', [
            'user_id' => $user->id,
            'type' => 'out',
            'work_location' => 'home',
        ])->assertOk();

        $this->travelTo('2026-06-01 18:04:00');

        $this->actingAs($user)->postJson('/api/attendance-records/clock/cancel', [
            'user_id' => $user->id,
            'type' => 'out',
        ])->assertOk()
            ->assertJsonPath('clock_out', '')
            ->assertJsonPath('work_location', '')
            ->assertJsonPath('meal_percentage', null)
            ->assertJsonPath('missed_meal', false)
            ->assertJsonPath('status', 'working');
    }

    public function test_user_cannot_cancel_clock_after_five_minutes(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 09:00:00');
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_in_recorded_at' => '2026-06-01 09:00:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);

        $this->travelTo('2026-06-01 09:06:00');

        $this->actingAs($user)->postJson('/api/attendance-records/clock/cancel', [
            'user_id' => $user->id,
            'type' => 'in',
        ])->assertStatus(422);
    }

    public function test_clock_in_is_rejected_when_already_clocked_in(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 10:00:00');
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertUnprocessable();
    }

    public function test_clock_in_is_rejected_on_non_working_day(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-07 09:00:00');

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertUnprocessable();
    }

    public function test_clock_in_is_rejected_when_paid_leave_request_exists(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 09:00:00');
        AttendanceRequest::create([
            'user_id' => $user->id,
            'type' => 'paid_leave',
            'request_date' => '2026-06-01',
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->postJson('/api/attendance-records/clock', [
                'user_id' => $user->id,
                'type' => 'in',
            ])
            ->assertUnprocessable();
    }

    public function test_missing_clock_out_records_are_returned_for_previous_days(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $this->travelTo('2026-06-01 09:00:00');

        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-31',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-06-01',
            'clock_in' => '09:00',
            'break_minutes' => 60,
            'status' => 'working',
        ]);
        AttendanceRecord::create([
            'user_id' => $user->id,
            'work_date' => '2026-05-30',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'break_minutes' => 60,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->getJson('/api/attendance-records?month=2026-06');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'missing_clock_out_records')
            ->assertJsonPath('missing_clock_out_records.0.work_date', '2026-05-31');
    }

    private function workdaySettings(string $clockIn, string $clockOut, int $breakMinutes): array
    {
        return collect(range(1, 6))
            ->mapWithKeys(fn (int $weekday) => [
                (string) $weekday => [
                    'default_clock_in' => $clockIn,
                    'default_clock_out' => $clockOut,
                    'default_break_minutes' => $breakMinutes,
                ],
            ])
            ->all();
    }
}

