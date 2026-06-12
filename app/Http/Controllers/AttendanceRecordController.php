<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\CalendarEntry;
use App\Models\User;
use App\Support\PaidLeaveAdjuster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class AttendanceRecordController extends Controller
{
    private array $calendarEntryDateTypeCache = [];

    private array $publicHolidayCache = [];

    private const REQUEST_REASON_CATEGORIES = [
        '私用のため',
        '体調不良のため',
        '家庭の事情のため',
        '交通機関遅延のため',
        'その他',
    ];

    public function index(Request $request)
    {
        $viewer = $request->user();
        $month = $request->string('month', now()->format('Y-m'))->toString();
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $users = $viewer->isAdmin()
            ? $this->activeUsersQuery()->get()
            : collect([$viewer]);
        $this->syncDuePlannedVacationAdjustments($users);
        $userId = $viewer->isAdmin()
            ? ($users->first()?->id ?? $viewer->id)
            : $viewer->id;

        if ($viewer->isAdmin()) {
            $displayDate = $request->validate([
                'date' => ['nullable', 'date_format:Y-m-d'],
            ])['date'] ?? now()->toDateString();
            $today = now()->toDateString();
            $userIds = $users->pluck('id');
            $requestsByKey = AttendanceRequest::query()
                ->whereIn('user_id', $userIds)
                ->whereDate('request_date', $displayDate)
                ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
                ->get()
                ->groupBy(fn (AttendanceRequest $request) => $request->user_id.'|'.$request->request_date->format('Y-m-d'));

            $recordsByUserId = AttendanceRecord::query()
                ->with('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes')
                ->whereIn('user_id', $userIds)
                ->whereDate('work_date', $displayDate)
                ->orderBy('user_id')
                ->get()
                ->keyBy('user_id');

            $records = $users->map(function (User $user) use ($recordsByUserId, $requestsByKey, $displayDate, $viewer) {
                $record = $recordsByUserId->get($user->id);

                if (! $record) {
                    return $this->serializeEmptyTodayRecord($user, $viewer, $displayDate, $requestsByKey->get($user->id.'|'.$displayDate, collect()));
                }

                return $this->serializeRecord($record, $viewer, $requestsByKey->get($record->user_id.'|'.$record->work_date->format('Y-m-d'), collect()));
            });

            $missingClockOutRecords = AttendanceRecord::query()
                ->with('user:id,name')
                ->whereIn('user_id', $userIds)
                ->whereNotNull('clock_in')
                ->whereNull('clock_out')
                ->whereDate('work_date', '<', $today)
                ->orderByDesc('work_date')
                ->get(['id', 'user_id', 'work_date', 'clock_in'])
                ->map(fn (AttendanceRecord $record) => [
                    'id' => $record->id,
                    'employee' => $record->user?->name,
                    'work_date' => $record->work_date->format('Y-m-d'),
                    'clock_in' => $record->clock_in ? substr($record->clock_in, 0, 5) : '',
                ]);
        } else {
            $requestsByDate = AttendanceRequest::query()
                ->where('user_id', $userId)
                ->whereBetween('request_date', [$start->toDateString(), $end->toDateString()])
                ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
                ->get()
                ->groupBy(fn (AttendanceRequest $request) => $request->request_date->format('Y-m-d'));

            $records = AttendanceRecord::query()
                ->with('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes')
                ->where('user_id', $userId)
                ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->orderByDesc('work_date')
                ->get()
                ->map(fn (AttendanceRecord $record) => $this->serializeRecord($record, $viewer, $requestsByDate->get($record->work_date->format('Y-m-d'), collect())));

            $missingClockOutRecords = AttendanceRecord::query()
                ->where('user_id', $userId)
                ->whereNotNull('clock_in')
                ->whereNull('clock_out')
                ->whereDate('work_date', '<', now()->toDateString())
                ->orderByDesc('work_date')
                ->get(['id', 'work_date', 'clock_in'])
                ->map(fn (AttendanceRecord $record) => [
                    'id' => $record->id,
                    'work_date' => $record->work_date->format('Y-m-d'),
                    'clock_in' => $record->clock_in ? substr($record->clock_in, 0, 5) : '',
                ]);
        }

        $clockStatus = $this->clockStatus($viewer);

        return response()->json([
            'viewer' => [
                'id' => $viewer->id,
                'name' => $viewer->name,
                'email' => $viewer->email,
                'role' => $viewer->role,
                'admin_level' => $viewer->admin_level ?? ($viewer->isAdmin() ? 'strong' : null),
                'is_admin' => $viewer->isAdmin(),
                'is_strong_admin' => $viewer->isStrongAdmin(),
            ],
            'users' => $users->map(fn (User $user) => $this->serializeUser($user)),
            'selected_user_id' => $userId,
            'records' => $records,
            'missing_clock_out_records' => $missingClockOutRecords,
            'summary' => $this->summary($records),
            'calendar_highlights' => $this->monthlyCalendarHighlights($start, $end),
            'clock' => $clockStatus,
            'display_date' => $viewer->isAdmin() ? ($displayDate ?? now()->toDateString()) : null,
        ]);
    }

    public function history(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'department' => ['nullable', 'string', 'max:255'],
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $department = $data['department'] ?? null;
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 15);
        $totalUsers = 1;
        if ($department) {
            $targetUsersQuery = $this->activeUsersQuery()->where('department', $department);
            $totalUsers = (clone $targetUsersQuery)->count();
            $targetUsers = $targetUsersQuery
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();
        } else {
            $targetUsers = collect([
                User::query()
                    ->where('role', 'user')
                    ->findOrFail($data['user_id'] ?? $this->activeUsersQuery()->value('id')),
            ]);
        }

        $this->syncDuePlannedVacationAdjustments($targetUsers);
        $requestsByKey = AttendanceRequest::query()
            ->whereIn('user_id', $targetUsers->pluck('id'))
            ->whereBetween('request_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get()
            ->groupBy(fn (AttendanceRequest $request) => $request->user_id.'|'.$request->request_date->format('Y-m-d'));

        $recordsByUserAndDate = AttendanceRecord::query()
            ->with('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes')
            ->whereIn('user_id', $targetUsers->pluck('id'))
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('work_date')
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->user_id.'|'.$record->work_date->format('Y-m-d'));

        $records = collect();
        for ($date = $end->copy(); $date->greaterThanOrEqualTo($start); $date->subDay()) {
            $dateKey = $date->format('Y-m-d');

            foreach ($targetUsers as $targetUser) {
                $record = $recordsByUserAndDate->get($targetUser->id.'|'.$dateKey);

                $records->push($record
                    ? $this->serializeRecord($record, $viewer, $requestsByKey->get($record->user_id.'|'.$dateKey, collect()))
                    : $this->serializeEmptyHistoryRecord($targetUser, $dateKey, $requestsByKey->get($targetUser->id.'|'.$dateKey, collect())));
            }
        }

        return response()->json([
            'mode' => $department ? 'department' : 'user',
            'department' => $department,
            'user' => $department ? null : $this->serializeUser($targetUsers->first()),
            'users' => $targetUsers->map(fn (User $user) => $this->serializeUser($user))->values(),
            'month' => $month,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'pagination' => [
                'page' => $department ? $page : 1,
                'per_page' => $department ? $perPage : 1,
                'total_users' => $department ? $totalUsers : $targetUsers->count(),
            ],
            'records' => $records,
        ]);
    }

    public function dashboard(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $periodEnd = $end->greaterThan(now()->startOfDay()) ? now()->startOfDay() : $end->copy();
        $users = $this->activeUsersQuery()->get();
        $this->syncDuePlannedVacationAdjustments($users);

        $userIds = $users->pluck('id');
        $requestsByKey = AttendanceRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('request_date', [$start->toDateString(), $periodEnd->toDateString()])
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get()
            ->groupBy(fn (AttendanceRequest $request) => $request->user_id.'|'.$request->request_date->format('Y-m-d'));

        $recordsByUserAndDate = AttendanceRecord::query()
            ->with('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes,department')
            ->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$start->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->user_id.'|'.$record->work_date->format('Y-m-d'));

        $summary = $this->blankDashboardSummary();
        $departmentSummaries = [];
        $userSummaries = [];

        foreach ($users as $user) {
            $department = $user->department ?: '未設定';
            $userSummary = $this->blankDashboardSummary();
            $userSummary['user_id'] = $user->id;
            $userSummary['name'] = $user->name;
            $userSummary['department'] = $department;

            if (! isset($departmentSummaries[$department])) {
                $departmentSummaries[$department] = $this->blankDashboardSummary();
                $departmentSummaries[$department]['department'] = $department;
            }

            for ($date = $start->copy(); $date->lessThanOrEqualTo($periodEnd); $date->addDay()) {
                $dateKey = $date->format('Y-m-d');
                $requests = $requestsByKey->get($user->id.'|'.$dateKey, collect());
                $record = $recordsByUserAndDate->get($user->id.'|'.$dateKey);
                $row = $this->dashboardRow($user, $dateKey, $record, $requests);

                $this->addDashboardRow($summary, $row);
                $this->addDashboardRow($departmentSummaries[$department], $row);
                $this->addDashboardRow($userSummary, $row);
            }

            $this->finalizeDashboardSummary($userSummary);
            $userSummaries[] = $userSummary;
        }

        foreach ($departmentSummaries as &$departmentSummary) {
            $this->finalizeDashboardSummary($departmentSummary);
        }
        unset($departmentSummary);

        $this->finalizeDashboardSummary($summary);

        return response()->json([
            'month' => $month,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'period_end_date' => $periodEnd->toDateString(),
            'summary' => $summary,
            'departments' => collect($departmentSummaries)
                ->sortBy('department', SORT_NATURAL)
                ->values()
                ->all(),
            'users' => collect($userSummaries)
                ->sortByDesc('worked_minutes')
                ->values()
                ->take(10)
                ->all(),
        ]);
    }

    public function payroll(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $periodEnd = $end->greaterThan(now()->startOfDay()) ? now()->startOfDay() : $end->copy();
        $users = $this->activeUsersQuery()->get();
        $this->syncDuePlannedVacationAdjustments($users);

        $userIds = $users->pluck('id');
        $requestsByKey = AttendanceRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('request_date', [$start->toDateString(), $periodEnd->toDateString()])
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get()
            ->groupBy(fn (AttendanceRequest $request) => $request->user_id.'|'.$request->request_date->format('Y-m-d'));

        $recordsByUserAndDate = AttendanceRecord::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$start->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->user_id.'|'.$record->work_date->format('Y-m-d'));

        $summary = [
            'users' => $users->count(),
            'total_pay' => 0,
            'net_pay' => 0,
            'total_deductions' => 0,
            'payable_minutes' => 0,
            'work_minutes' => 0,
            'paid_leave_minutes' => 0,
            'paid_leave_minutes' => 0,
            'social_insurance_total' => 0,
            'employment_insurance_total' => 0,
            'income_tax_total' => 0,
            'resident_tax_total' => 0,
            'missing_wage_count' => 0,
        ];
        $departmentSummaries = [];
        $rows = [];

        foreach ($users as $user) {
            $department = $user->department ?: '未設定';
            $hourlyWage = (int) ($user->hourly_wage ?? 0);
            $row = [
                'user_id' => $user->id,
                'name' => $user->name,
                'management_number' => $user->management_number ?? '',
                'department' => $department,
                'hourly_wage' => $hourlyWage,
                'work_minutes' => 0,
                'paid_leave_minutes' => 0,
                'payable_minutes' => 0,
                'gross_pay' => 0,
                'attendance_days' => 0,
                'paid_leave_days' => 0.0,
                'absence_days' => 0,
                'business_support_days' => 0,
            ];

            if (! isset($departmentSummaries[$department])) {
                $departmentSummaries[$department] = [
                    'department' => $department,
                    'users' => 0,
                'total_pay' => 0,
                'net_pay' => 0,
                'total_deductions' => 0,
                'payable_minutes' => 0,
                'work_minutes' => 0,
                'paid_leave_minutes' => 0,
                'social_insurance_total' => 0,
                'employment_insurance_total' => 0,
                'income_tax_total' => 0,
                'resident_tax_total' => 0,
                'missing_wage_count' => 0,
            ];
            }
            $departmentSummaries[$department]['users']++;

            for ($date = $start->copy(); $date->lessThanOrEqualTo($periodEnd); $date->addDay()) {
                $dateKey = $date->format('Y-m-d');
                $requests = $requestsByKey->get($user->id.'|'.$dateKey, collect());
                $record = $recordsByUserAndDate->get($user->id.'|'.$dateKey);
                $daily = $this->payrollDay($user, $dateKey, $record, $requests);

                $row['work_minutes'] += $daily['work_minutes'];
                $row['paid_leave_minutes'] += $daily['paid_leave_minutes'];
                $row['payable_minutes'] += $daily['payable_minutes'];
                $row['attendance_days'] += $daily['attendance_day'];
                $row['paid_leave_days'] += $daily['paid_leave_day'];
                $row['absence_days'] += $daily['absence_day'];
                $row['business_support_days'] += $daily['business_support_day'];
            }

            $row['gross_pay'] = $hourlyWage > 0
                ? (int) round(($row['payable_minutes'] / 60) * $hourlyWage)
                : 0;
            $row['health_insurance_deduction'] = (int) ($user->health_insurance_deduction ?? 0);
            $row['nursing_care_insurance_deduction'] = (int) ($user->nursing_care_insurance_deduction ?? 0);
            $row['welfare_pension_deduction'] = (int) ($user->welfare_pension_deduction ?? 0);
            $row['employment_insurance_rate'] = (float) ($user->employment_insurance_rate ?? 0);
            $row['employment_insurance_deduction'] = (int) round($row['gross_pay'] * ($row['employment_insurance_rate'] / 100));
            $row['income_tax_deduction'] = (int) ($user->income_tax_deduction ?? 0);
            $row['resident_tax_deduction'] = (int) ($user->resident_tax_deduction ?? 0);
            $row['social_insurance_total'] = $row['health_insurance_deduction']
                + $row['nursing_care_insurance_deduction']
                + $row['welfare_pension_deduction'];
            $row['total_deductions'] = $row['social_insurance_total']
                + $row['employment_insurance_deduction']
                + $row['income_tax_deduction']
                + $row['resident_tax_deduction'];
            $row['net_pay'] = max(0, $row['gross_pay'] - $row['total_deductions']);
            $row['missing_wage'] = $hourlyWage <= 0 && $row['payable_minutes'] > 0;

            $summary['total_pay'] += $row['gross_pay'];
            $summary['net_pay'] += $row['net_pay'];
            $summary['total_deductions'] += $row['total_deductions'];
            $summary['payable_minutes'] += $row['payable_minutes'];
            $summary['work_minutes'] += $row['work_minutes'];
            $summary['paid_leave_minutes'] += $row['paid_leave_minutes'];
            $summary['social_insurance_total'] += $row['social_insurance_total'];
            $summary['employment_insurance_total'] += $row['employment_insurance_deduction'];
            $summary['income_tax_total'] += $row['income_tax_deduction'];
            $summary['resident_tax_total'] += $row['resident_tax_deduction'];
            if ($row['missing_wage']) {
                $summary['missing_wage_count']++;
                $departmentSummaries[$department]['missing_wage_count']++;
            }

            $departmentSummaries[$department]['total_pay'] += $row['gross_pay'];
            $departmentSummaries[$department]['net_pay'] += $row['net_pay'];
            $departmentSummaries[$department]['total_deductions'] += $row['total_deductions'];
            $departmentSummaries[$department]['payable_minutes'] += $row['payable_minutes'];
            $departmentSummaries[$department]['work_minutes'] += $row['work_minutes'];
            $departmentSummaries[$department]['paid_leave_minutes'] += $row['paid_leave_minutes'];
            $departmentSummaries[$department]['social_insurance_total'] += $row['social_insurance_total'];
            $departmentSummaries[$department]['employment_insurance_total'] += $row['employment_insurance_deduction'];
            $departmentSummaries[$department]['income_tax_total'] += $row['income_tax_deduction'];
            $departmentSummaries[$department]['resident_tax_total'] += $row['resident_tax_deduction'];

            $rows[] = $row;
        }

        return response()->json([
            'month' => $month,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'period_end_date' => $periodEnd->toDateString(),
            'summary' => $summary,
            'departments' => collect($departmentSummaries)->sortBy('department', SORT_NATURAL)->values()->all(),
            'rows' => collect($rows)
                ->sortBy([
                    ['department', 'asc'],
                    ['management_number', 'asc'],
                    ['name', 'asc'],
                ])
                ->values()
                ->all(),
        ]);
    }

    public function payrollSlipPdf(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $periodEnd = $end->greaterThan(now()->startOfDay()) ? now()->startOfDay() : $end->copy();
        $targetUser = User::query()
            ->where('role', 'user')
            ->findOrFail($data['user_id']);

        $this->syncDuePlannedVacationAdjustments(collect([$targetUser]));

        $requestsByDate = AttendanceRequest::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('request_date', [$start->toDateString(), $periodEnd->toDateString()])
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get()
            ->groupBy(fn (AttendanceRequest $request) => $request->request_date->format('Y-m-d'));

        $recordsByDate = AttendanceRecord::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('work_date', [$start->toDateString(), $periodEnd->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->work_date->format('Y-m-d'));

        $row = [
            'attendance_days' => 0,
            'remote_days' => 0,
            'work_minutes' => 0,
            'paid_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'holiday_overtime_minutes' => 0,
            'absence_days' => 0,
            'late_days' => 0,
            'early_leave_days' => 0,
            'paid_leave_days' => 0.0,
            'condolence_leave_days' => 0,
            'gross_pay' => 0,
            'basic_pay' => 0,
            'report_allowance' => 0,
            'health_insurance_deduction' => (int) ($targetUser->health_insurance_deduction ?? 0),
            'nursing_care_insurance_deduction' => (int) ($targetUser->nursing_care_insurance_deduction ?? 0),
            'welfare_pension_deduction' => (int) ($targetUser->welfare_pension_deduction ?? 0),
            'child_care_contribution_deduction' => 0,
            'employment_insurance_rate' => (float) ($targetUser->employment_insurance_rate ?? 0),
            'income_tax_deduction' => (int) ($targetUser->income_tax_deduction ?? 0),
            'resident_tax_deduction' => (int) ($targetUser->resident_tax_deduction ?? 0),
            'transportation_expense' => 0,
            'meal_deduction' => 0,
            'other_adjustment' => 0,
        ];

        for ($date = $start->copy(); $date->lessThanOrEqualTo($periodEnd); $date->addDay()) {
            $dateKey = $date->format('Y-m-d');
            $requests = $requestsByDate->get($dateKey, collect());
            $record = $recordsByDate->get($dateKey);
            $daily = $this->payrollDay($targetUser, $dateKey, $record, $requests);
            $status = $this->pdfStatusKey($targetUser, $dateKey, $record, $requests, $this->isNonWorkingDate($dateKey, $targetUser));

            $row['work_minutes'] += $daily['work_minutes'];
            $row['paid_leave_minutes'] += $daily['paid_leave_minutes'];
            $row['paid_leave_days'] += $daily['paid_leave_day'];
            $row['absence_days'] += $daily['absence_day'];
            $row['attendance_days'] += $daily['attendance_day'];

            if ($record?->work_location === 'remote' && $daily['attendance_day'] > 0) {
                $row['remote_days']++;
            }

            if (in_array($status, ['late', 'late_and_early'], true)) {
                $row['late_days']++;
            }

            if (in_array($status, ['early_leave', 'late_and_early'], true)) {
                $row['early_leave_days']++;
            }
        }

        $hourlyWage = (int) ($targetUser->hourly_wage ?? 0);
        $payableMinutes = $row['work_minutes'] + $row['paid_leave_minutes'];
        $row['basic_pay'] = $hourlyWage > 0 ? (int) round(($payableMinutes / 60) * $hourlyWage) : 0;
        $row['gross_pay'] = $row['basic_pay'] + $row['report_allowance'];
        $row['employment_insurance_deduction'] = (int) round($row['gross_pay'] * ($row['employment_insurance_rate'] / 100));
        $row['insurance_total'] = $row['health_insurance_deduction']
            + $row['nursing_care_insurance_deduction']
            + $row['welfare_pension_deduction']
            + $row['child_care_contribution_deduction']
            + $row['employment_insurance_deduction'];
        $row['withholding_total'] = $row['income_tax_deduction'] + $row['resident_tax_deduction'];
        $row['total_deductions'] = $row['insurance_total']
            + $row['withholding_total']
            + $row['meal_deduction']
            + max(0, -1 * $row['other_adjustment']);
        $row['net_pay'] = $row['gross_pay']
            + $row['transportation_expense']
            + max(0, $row['other_adjustment'])
            - $row['total_deductions'];
        $row['payable_minutes'] = $payableMinutes;

        $payDate = $start->copy()->addMonthNoOverflow()->day(15);
        $html = view('payroll-slip-pdf', [
            'user' => $targetUser,
            'month' => $start,
            'periodEnd' => $periodEnd,
            'payDate' => $payDate,
            'row' => $row,
        ])->render();

        $mpdf = $this->makePdf('A4', 4, 6, 19, 6);
        $mpdf->WriteHTML($html);

        $filename = sprintf('%s_%s_%s.pdf', $targetUser->name, "\u{7D66}\u{4E0E}\u{660E}\u{7D30}", $start->format('Y-m'));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"payroll-slip.pdf\"; filename*=UTF-8''".rawurlencode($filename),
        ]);

        $templatePath = resource_path('pdf/payroll-slip-template.pdf');

        if (is_file($templatePath)) {
            $mpdf = $this->makePdf('A4', 0, 0, 0, 0);
            $mpdf->AddPage();
            $mpdf->setSourceFile($templatePath);
            $template = $mpdf->importPage(1);
            $mpdf->useTemplate($template, 0, 0, 210, 297);
            $mpdf->SetFillColor(255, 255, 255);

            $cover = function (float $x, float $y, float $w, float $h) use ($mpdf) {
                $mpdf->Rect($x, $y, $w, $h, 'F');
            };
            $write = function (float $x, float $y, float $w, float $h, string $value, string $align = 'C', float $size = 5.0, bool $bold = false) use ($mpdf) {
                $mpdf->WriteFixedPosHTML(
                    '<div style="color:#111;font-family:notosansjp,sans-serif;font-size:'.$size.'pt;font-weight:'.($bold ? '700' : '400').';line-height:1.05;text-align:'.$align.';">'.e($value).'</div>',
                    $x,
                    $y,
                    $w,
                    $h
                );
            };
            $yen = fn ($value) => '¥'.number_format((int) round($value));
            $yen = fn ($value) => "\u{00A5}".number_format((int) round($value));
            $hours = fn ($minutes) => number_format(max(0, (int) $minutes) / 60, 2);
            $days = fn ($value, $decimals = 0) => number_format((float) $value, $decimals);
            $paidLeaveDays = floor($row['paid_leave_days']) == $row['paid_leave_days']
                ? (string) (int) $row['paid_leave_days']
                : number_format($row['paid_leave_days'], 1);
            $payEraYear = max(1, (int) $payDate->format('Y') - 2018);
            $columns = [18.5, 39.5, 60.5, 81.5, 102.5, 123.5, 144.5, 165.5];

            $cover(17, 15.2, 180, 5.8);
            $write(18, 16.8, 25, 4, $start->format('Y年n月'), 'L');
            $write(31, 16.8, 55, 4, 'アクティブサポート株式会社', 'L');
            $write(116, 16.8, 10, 4, '氏名', 'L');
            $write(128, 16.8, 33, 4, $targetUser->name, 'L', 5.5, true);
            $write(160, 16.8, 8, 4, '殿', 'L');
            $write(169, 16.8, 11, 4, '所属', 'L');
            $write(181, 16.8, 23, 4, $targetUser->department ?: '未設定', 'L');

            $cover(94, 25.1, 28, 4.2);
            $cover(17, 15.2, 180, 5.8);
            $write(18, 16.8, 25, 4, $start->year."\u{5E74}".$start->month."\u{6708}", 'L');
            $write(31, 16.8, 55, 4, "\u{30A2}\u{30AF}\u{30C6}\u{30A3}\u{30D6}\u{30B5}\u{30DD}\u{30FC}\u{30C8}\u{682A}\u{5F0F}\u{4F1A}\u{793E}", 'L');
            $write(116, 16.8, 10, 4, "\u{6C0F}\u{540D}", 'L');
            $write(128, 16.8, 33, 4, $targetUser->name, 'L', 5.5, true);
            $write(160, 16.8, 8, 4, "\u{6BBF}", 'L');
            $write(169, 16.8, 11, 4, "\u{6240}\u{5C5E}", 'L');
            $write(181, 16.8, 23, 4, $targetUser->department ?: "\u{672A}\u{8A2D}\u{5B9A}", 'L');
            $write(94, 25.7, 28, 4, $yen($row['net_pay']), 'C', 5.0, true);
            $cover(135, 75.7, 45, 4.2);
            $write(136, 76.2, 43, 4, '令和'.$payEraYear.'年'.$payDate->month.'月'.$payDate->day.'日', 'C');
            $cover(135, 75.7, 45, 4.2);
            $write(136, 76.2, 43, 4, "\u{4EE4}\u{548C}".$payEraYear."\u{5E74}".$payDate->month."\u{6708}".$payDate->day."\u{65E5}", 'C');

            foreach ([33.1, 37.8, 45.0, 50.0, 56.8, 61.6] as $y) {
                foreach ($columns as $x) {
                    $cover($x + 0.7, $y, 16.6, 3.9);
                }
            }
            foreach ([68.8, 73.0, 77.1] as $y) {
                foreach ([58, 84, 106] as $x) {
                    $cover($x + 0.7, $y, 17.2, 3.9);
                }
            }
            $cover($columns[4] + 0.7, 50.0, 34, 3.9);
            $cover($columns[4] + 0.7, 61.6, 22, 3.9);

            $write($columns[0], 33.7, 18, 4, $days($row['attendance_days'], 2));
            $write($columns[1], 33.7, 18, 4, $days($row['remote_days']));
            $write($columns[2], 33.7, 18, 4, $hours($row['work_minutes']));
            $write($columns[3], 33.7, 18, 4, $hours($row['overtime_minutes']));
            $write($columns[4], 33.7, 18, 4, $days($row['holiday_overtime_minutes']));
            $write($columns[5], 33.7, 18, 4, $days($row['absence_days']));
            $write($columns[6], 33.7, 18, 4, $days($row['late_days']));
            $write($columns[7], 33.7, 18, 4, $days($row['early_leave_days']));

            $write($columns[0], 38.4, 18, 4, $paidLeaveDays);
            $write($columns[1], 38.4, 18, 4, $days($row['condolence_leave_days']));
            $write($columns[2], 38.4, 18, 4, '0');

            $write($columns[0], 45.5, 18, 4, $yen($row['basic_pay']));
            $write($columns[1], 45.5, 18, 4, $yen(0));
            $write($columns[2], 45.5, 18, 4, $yen(0));
            $write($columns[3], 45.5, 18, 4, $yen(0));
            $write($columns[4], 45.5, 18, 4, $yen(0));
            $write($columns[5], 45.5, 18, 4, $yen(0));
            $write($columns[6], 45.5, 18, 4, $yen($row['report_allowance']));
            $write($columns[7], 45.5, 18, 4, $yen(0));

            $write($columns[0], 50.2, 18, 4, $yen(0));
            $write($columns[1], 50.2, 18, 4, $yen(0));
            $write($columns[2], 50.2, 18, 4, $yen(0));
            $write($columns[3], 50.2, 18, 4, $yen(0));
            $write($columns[4], 50.2, 30, 4, $yen($row['gross_pay']), 'C', 5.0, true);

            $write($columns[0], 57.0, 18, 4, $yen($row['health_insurance_deduction'] + $row['nursing_care_insurance_deduction']));
            $write($columns[1], 57.0, 18, 4, $yen($row['welfare_pension_deduction']));
            $write($columns[2], 57.0, 18, 4, $yen($row['child_care_contribution_deduction']));
            $write($columns[3], 57.0, 18, 4, $yen($row['employment_insurance_deduction']));

            $write($columns[0], 61.8, 18, 4, $yen($row['income_tax_deduction']));
            $write($columns[1], 61.8, 18, 4, $yen($row['resident_tax_deduction']));
            $write($columns[2], 61.8, 20, 4, $yen($row['insurance_total']));
            $write($columns[3], 61.8, 20, 4, $yen($row['withholding_total']));
            $write($columns[4], 61.8, 20, 4, $yen($row['total_deductions']), 'C', 5.0, true);

            $write(58, 68.9, 18, 4, $yen($row['transportation_expense']));
            $write(84, 68.9, 12, 4, '0');
            $write(106, 68.9, 18, 4, $yen(0));
            $write(58, 73.0, 18, 4, $yen($row['meal_deduction']));
            $write(84, 73.0, 12, 4, '0');
            $write(106, 73.0, 18, 4, $yen(0));
            $write(58, 77.1, 18, 4, $yen($row['other_adjustment']));
            $write(84, 77.1, 12, 4, '0');
            $write(106, 77.1, 18, 4, $yen(0));

            $filename = sprintf('%s_給与明細_%s.pdf', $targetUser->name, $start->format('Y-m'));

            return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"payroll-slip.pdf\"; filename*=UTF-8''".rawurlencode($filename),
            ]);
        }

        $html = view('payroll-slip-pdf', [
            'user' => $targetUser,
            'month' => $start,
            'periodEnd' => $periodEnd,
            'payDate' => $payDate,
            'row' => $row,
        ])->render();

        $mpdf = $this->makePdf('A4', 6, 6, 10, 6);
        $mpdf->WriteHTML($html);

        $filename = sprintf('%s_給与明細_%s.pdf', $targetUser->name, $start->format('Y-m'));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"payroll-slip.pdf\"; filename*=UTF-8''".rawurlencode($filename),
        ]);

        $payEraYear = max(1, (int) $payDate->format('Y') - 2018);
        $templatePath = resource_path('pdf/payroll-slip-template.pdf');

        $mpdf = $this->makePdf('A4', 0, 0, 0, 0);
        if (is_file($templatePath)) {
            $mpdf->AddPage();
            $mpdf->setSourceFile($templatePath);
            $template = $mpdf->importPage(1);
            $mpdf->useTemplate($template, 0, 0, 210, 297);

            $write = function (float $x, float $y, float $w, float $h, string $value, string $align = 'C', float $size = 5.0, bool $bold = false) use ($mpdf) {
                $mpdf->WriteFixedPosHTML(
                    '<div style="background:#fff;color:#111;font-family:notosansjp,sans-serif;font-size:'.$size.'pt;font-weight:'.($bold ? '700' : '400').';line-height:1.05;text-align:'.$align.';">'.e($value).'</div>',
                    $x,
                    $y,
                    $w,
                    $h
                );
            };
            $yen = fn ($value) => '¥'.number_format((int) round($value));
            $hours = fn ($minutes) => number_format(max(0, (int) $minutes) / 60, 2);
            $days = fn ($value, $decimals = 0) => number_format((float) $value, $decimals);
            $paidLeaveDays = floor($row['paid_leave_days']) == $row['paid_leave_days']
                ? (string) (int) $row['paid_leave_days']
                : number_format($row['paid_leave_days'], 1);

            $write(17.5, 16.0, 26, 4, $start->format('Y年n月'), 'L');
            $write(128, 16.0, 30, 4, $targetUser->name, 'L', 5.5, true);
            $write(172, 16.0, 22, 4, $targetUser->department ?: '未設定', 'L');
            $write(95, 24.6, 34, 4.5, $yen($row['net_pay']), 'C', 5.0, true);
            $write(137, 72.4, 40, 4, '令和'.$payEraYear.'年'.$payDate->month.'月'.$payDate->day.'日', 'C');

            $columns = [21.5, 42.5, 63.5, 84.5, 105.5, 126.5, 147.5, 168.5];
            $write($columns[0], 33.5, 16, 4, $days($row['attendance_days'], 2));
            $write($columns[1], 33.5, 16, 4, $days($row['remote_days']));
            $write($columns[2], 33.5, 16, 4, $hours($row['work_minutes']));
            $write($columns[3], 33.5, 16, 4, $hours($row['overtime_minutes']));
            $write($columns[4], 33.5, 16, 4, $days($row['holiday_overtime_minutes']));
            $write($columns[5], 33.5, 16, 4, $days($row['absence_days']));
            $write($columns[6], 33.5, 16, 4, $days($row['late_days']));
            $write($columns[7], 33.5, 16, 4, $days($row['early_leave_days']));

            $write($columns[0], 38.2, 16, 4, $paidLeaveDays);
            $write($columns[1], 38.2, 16, 4, $days($row['condolence_leave_days']));
            $write($columns[2], 38.2, 16, 4, '0');

            $write($columns[0], 45.2, 16, 4, $yen($row['basic_pay']));
            $write($columns[1], 45.2, 16, 4, $yen(0));
            $write($columns[2], 45.2, 16, 4, $yen(0));
            $write($columns[3], 45.2, 16, 4, $yen(0));
            $write($columns[4], 45.2, 16, 4, $yen(0));
            $write($columns[5], 45.2, 16, 4, $yen(0));
            $write($columns[6], 45.2, 18, 4, $yen($row['report_allowance']));
            $write($columns[7], 45.2, 16, 4, $yen(0));

            $write($columns[0], 50.4, 16, 4, $yen(0));
            $write($columns[1], 50.4, 16, 4, $yen(0));
            $write($columns[2], 50.4, 16, 4, $yen(0));
            $write($columns[3], 50.4, 16, 4, $yen(0));
            $write($columns[4], 50.4, 20, 4, $yen($row['gross_pay']), 'C', 5.0, true);

            $write($columns[0], 57.2, 16, 4, $yen($row['health_insurance_deduction'] + $row['nursing_care_insurance_deduction']));
            $write($columns[1], 57.2, 16, 4, $yen($row['welfare_pension_deduction']));
            $write($columns[2], 57.2, 16, 4, $yen($row['child_care_contribution_deduction']));
            $write($columns[3], 57.2, 16, 4, $yen($row['employment_insurance_deduction']));

            $write($columns[0], 62.0, 16, 4, $yen($row['income_tax_deduction']));
            $write($columns[1], 62.0, 16, 4, $yen($row['resident_tax_deduction']));
            $write($columns[2], 62.0, 18, 4, $yen($row['insurance_total']));
            $write($columns[3], 62.0, 18, 4, $yen($row['withholding_total']));
            $write($columns[4], 62.0, 18, 4, $yen($row['total_deductions']), 'C', 5.0, true);

            $write(58, 69.3, 18, 4, $yen($row['transportation_expense']));
            $write(84, 69.3, 12, 4, '0');
            $write(106, 69.3, 18, 4, $yen(0));
            $write(58, 73.4, 18, 4, $yen($row['meal_deduction']));
            $write(84, 73.4, 12, 4, '0');
            $write(106, 73.4, 18, 4, $yen(0));
            $write(58, 77.5, 18, 4, $yen($row['other_adjustment']));
            $write(84, 77.5, 12, 4, '0');
            $write(106, 77.5, 18, 4, $yen(0));
        } else {
            $html = view('payroll-slip-pdf', [
                'user' => $targetUser,
                'month' => $start,
                'periodEnd' => $periodEnd,
                'payDate' => $payDate,
                'row' => $row,
            ])->render();
            $mpdf->WriteHTML($html);
        }

        $filename = sprintf('%s_給与明細_%s.pdf', $targetUser->name, $start->format('Y-m'));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"payroll-slip.pdf\"; filename*=UTF-8''".rawurlencode($filename),
        ]);
    }

    public function historyPdf(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $targetUser = User::query()
            ->where('role', 'user')
            ->findOrFail($data['user_id']);

        $this->syncDuePlannedVacationAdjustments(collect([$targetUser]));

        $requestsByKey = AttendanceRequest::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('request_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get()
            ->groupBy(fn (AttendanceRequest $request) => $request->request_date->format('Y-m-d'));

        $recordsByDate = AttendanceRecord::query()
            ->with('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes')
            ->where('user_id', $targetUser->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->work_date->format('Y-m-d'));

        $rows = collect();
        $summary = [
            'attendance_days' => 0,
            'holiday_days' => 0,
            'paid_leave_days' => 0.0,
            'absence_days' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'break_minutes' => 0,
            'within_minutes' => 0,
            'overtime_minutes' => 0,
        ];

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $dateKey = $date->format('Y-m-d');
            $record = $recordsByDate->get($dateKey);
            $requests = $requestsByKey->get($dateKey, collect());
            $row = $this->pdfAttendanceRow($targetUser, $date, $record, $requests);
            $rows->push($row);

            if ($row['status_key'] === 'attendance') {
                $summary['attendance_days']++;
            }
            if ($row['status_key'] === 'holiday') {
                $summary['holiday_days']++;
            }
            if ($row['status_key'] === 'absence') {
                $summary['absence_days']++;
            }
            if (in_array($row['status_key'], ['paid_leave', 'planned_vacation'], true)) {
                $summary['paid_leave_days'] += 1;
            }
            if (in_array($row['status_key'], ['morning_paid_leave', 'afternoon_paid_leave'], true)) {
                $summary['paid_leave_days'] += 0.5;
            }

            $summary['late_minutes'] += $row['late_minutes'];
            $summary['early_leave_minutes'] += $row['early_leave_minutes'];
            $summary['break_minutes'] += $row['declared_break_minutes'];
            $summary['within_minutes'] += $row['within_minutes'];
            $summary['overtime_minutes'] += $row['overtime_minutes'];
        }

        $html = view('attendance-history-pdf', [
            'user' => $targetUser,
            'month' => $start,
            'rows' => $rows,
            'summary' => $summary,
            'eraYear' => $this->reiwaYear((int) $start->year),
        ])->render();

        $mpdf = $this->makePdf();
        $mpdf->WriteHTML($html);

        $filename = sprintf('%s_過去勤怠_%s.pdf', $targetUser->name, $start->format('Y-m'));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"attendance-history.pdf\"; filename*=UTF-8''".rawurlencode($filename),
        ]);
    }

    public function historyCompanyPdf(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $targetUser = User::query()
            ->where('role', 'user')
            ->findOrFail($data['user_id']);

        $this->syncDuePlannedVacationAdjustments(collect([$targetUser]));

        $requestsByKey = AttendanceRequest::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('request_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get()
            ->groupBy(fn (AttendanceRequest $request) => $request->request_date->format('Y-m-d'));

        $recordsByDate = AttendanceRecord::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->work_date->format('Y-m-d'));

        $rows = collect();
        $summary = [
            'attendance_days' => 0,
            'holiday_days' => 0,
            'paid_leave_days' => 0.0,
            'absence_days' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'scheduled_days' => 0,
        ];

        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $dateKey = $date->format('Y-m-d');
            $record = $recordsByDate->get($dateKey);
            $requests = $requestsByKey->get($dateKey, collect());
            $baseRow = $this->pdfAttendanceRow($targetUser, $date, $record, $requests);
            $companyRow = $this->companyPdfAttendanceRow($baseRow, $record, $targetUser);
            $rows->push($companyRow);

            if ($baseRow['status_key'] === 'attendance') {
                $summary['attendance_days']++;
            }
            if ($baseRow['status_key'] === 'holiday') {
                $summary['holiday_days']++;
            } else {
                $summary['scheduled_days']++;
            }
            if ($baseRow['status_key'] === 'absence') {
                $summary['absence_days']++;
            }
            if (in_array($baseRow['status_key'], ['paid_leave', 'planned_vacation'], true)) {
                $summary['paid_leave_days'] += 1;
            }
            if (in_array($baseRow['status_key'], ['morning_paid_leave', 'afternoon_paid_leave'], true)) {
                $summary['paid_leave_days'] += 0.5;
            }
            $summary['late_minutes'] += $baseRow['late_minutes'];
            $summary['early_leave_minutes'] += $baseRow['early_leave_minutes'];
        }

        $summary['attendance_rate'] = $summary['scheduled_days'] > 0
            ? round(($summary['attendance_days'] / $summary['scheduled_days']) * 100, 1)
            : 0;

        $html = view('attendance-history-company-pdf', [
            'user' => $targetUser,
            'month' => $start,
            'rows' => $rows,
            'summary' => $summary,
            'eraYear' => $this->reiwaYear((int) $start->year),
            'outputDate' => now()->timezone(config('app.timezone'))->format('Y/m/d'),
        ])->render();

        $mpdf = $this->makePdf();
        $mpdf->WriteHTML($html);

        $filename = sprintf('%s_会社保管用勤怠_%s.pdf', $targetUser->name, $start->format('Y-m'));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"company-attendance-history.pdf\"; filename*=UTF-8''".rawurlencode($filename),
        ]);
    }

    public function businessReports(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $targetUser = User::query()
            ->where('role', 'user')
            ->findOrFail($data['user_id']);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $reportDate = $data['date'] ?? now()->toDateString();
        $users = $this->activeUsersQuery()->get();
        $todayRecords = AttendanceRecord::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('work_date', $reportDate)
            ->get()
            ->keyBy('user_id');

        $todayReports = $users->map(function (User $user) use ($todayRecords, $reportDate) {
            $record = $todayRecords->get($user->id);

            return $this->serializeBusinessReport($user, $reportDate, $record?->note ?? '', $record ? $this->adminCommentForRecord($record) : '');
        });

        $monthlyReports = AttendanceRecord::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('note')
            ->where('note', '<>', '')
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (AttendanceRecord $record) => $this->serializeBusinessReport($targetUser, $record->work_date->format('Y-m-d'), $record->note ?? '', $this->adminCommentForRecord($record)));

        return response()->json([
            'month' => $month,
            'today' => $reportDate,
            'display_date' => $reportDate,
            'selected_user' => $this->serializeUser($targetUser),
            'today_reports' => $todayReports,
            'monthly_reports' => $monthlyReports,
        ]);
    }

    public function businessReportsPdf(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $targetUser = User::query()
            ->where('role', 'user')
            ->findOrFail($data['user_id']);
        $month = $data['month'] ?? now()->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $recordsByDate = AttendanceRecord::query()
            ->where('user_id', $targetUser->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->work_date->format('Y-m-d'));

        $rows = collect();
        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $dateKey = $date->format('Y-m-d');
            $record = $recordsByDate->get($dateKey);
            $note = trim((string) ($record?->note ?? ''));
            $adminComment = $record ? $this->adminCommentForRecord($record) : '';

            $rows->push([
                'date' => $date->format('Y/m/d'),
                'weekday' => ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek],
                'weekday_key' => $date->dayOfWeek,
                'note' => $note,
                'admin_comment' => $adminComment,
            ]);
        }

        $html = view('business-report-pdf', [
            'user' => $targetUser,
            'month' => $start,
            'rows' => $rows,
            'eraYear' => $this->reiwaYear((int) $start->year),
        ])->render();

        $mpdf = $this->makePdf('A4', 12, 12, 12, 12);
        $mpdf->WriteHTML($html);

        $filename = sprintf('%s_業務報告_%s.pdf', $targetUser->name, $start->format('Y-m'));

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"business-report.pdf\"; filename*=UTF-8''".rawurlencode($filename),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->authorizeUserAccess($request, (int) $data['user_id']);
        $this->authorizeEditableDate($request, $data['work_date']);

        $this->pullAttendanceRequestData($data);

        $record = DB::transaction(function () use ($data) {
            $record = AttendanceRecord::query()->updateOrCreate(
                ['user_id' => $data['user_id'], 'work_date' => $data['work_date']],
                $data,
            );

            $adjuster = app(PaidLeaveAdjuster::class);
            $adjuster->syncForAttendanceRecord($record);
            $adjuster->syncCalendarPlannedVacationsForRecordDate($record);

            return $record;
        });

        return response()->json($this->serializeRecord(
            $record->load('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes'),
            $request->user(),
            $this->requestsForRecord($record),
        ), 201);
    }

    public function update(Request $request, AttendanceRecord $attendanceRecord)
    {
        $data = $this->validated($request, $attendanceRecord->id);
        $this->authorizeUserAccess($request, $attendanceRecord->user_id);
        $this->authorizeUserAccess($request, (int) $data['user_id']);
        $this->authorizeEditableDate($request, $attendanceRecord->work_date->format('Y-m-d'));
        $this->authorizeEditableDate($request, $data['work_date']);

        $this->pullAttendanceRequestData($data);

        DB::transaction(function () use ($attendanceRecord, $data) {
            $attendanceRecord->update($data);
            $adjuster = app(PaidLeaveAdjuster::class);
            $adjuster->syncForAttendanceRecord($attendanceRecord);
            $adjuster->syncCalendarPlannedVacationsForRecordDate($attendanceRecord);
        });

        return response()->json($this->serializeRecord(
            $attendanceRecord->load('user:id,name,email,retirement_date,retired_at,commute_limit_days,workday_settings,default_clock_in,default_clock_out,default_break_minutes'),
            $request->user(),
            $this->requestsForRecord($attendanceRecord),
        ));
    }

    public function destroy(Request $request, AttendanceRecord $attendanceRecord)
    {
        $this->authorizeUserAccess($request, $attendanceRecord->user_id);
        $this->authorizeEditableDate($request, $attendanceRecord->work_date->format('Y-m-d'));

        DB::transaction(function () use ($attendanceRecord) {
            $adjuster = app(PaidLeaveAdjuster::class);
            $user = $attendanceRecord->user ?? User::query()->findOrFail($attendanceRecord->user_id);
            $workDate = $attendanceRecord->work_date->copy();

            $adjuster->removeForAttendanceRecord($attendanceRecord);
            $attendanceRecord->delete();
            $adjuster->syncCalendarPlannedVacationsForUserDate($user, $workDate);
        });

        return response()->noContent();
    }

    public function clock(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', Rule::in(['in', 'out'])],
            'declared_clock_in' => ['nullable', 'date_format:H:i'],
            'declared_clock_out' => ['nullable', 'date_format:H:i', 'after_or_equal:declared_clock_in'],
            'declared_break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'work_location' => ['nullable', Rule::in(['office', 'home'])],
            'meal_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'missed_meal' => ['nullable', 'boolean'],
        ]);
        $this->authorizeUserAccess($request, (int) $data['user_id']);

        $now = now();
        $targetUser = User::query()->findOrFail($data['user_id']);
        $weekdaySettings = $targetUser->normalizedWorkdaySettings()[(string) $now->dayOfWeekIso] ?? null;
        $record = AttendanceRecord::query()
            ->where('user_id', $data['user_id'])
            ->whereDate('work_date', $now->toDateString())
            ->first();

        if ($data['type'] === 'in') {
            $blockedReason = $this->clockInBlockedReason($targetUser, $record, $now->toDateString());

            if ($blockedReason) {
                throw ValidationException::withMessages([
                    'type' => $blockedReason,
                ]);
            }
        } else {
            $blockedReason = $this->clockOutBlockedReason($record);

            if ($blockedReason) {
                throw ValidationException::withMessages([
                    'type' => $blockedReason,
                ]);
            }
        }

        $record ??= AttendanceRecord::query()->create([
            'user_id' => $data['user_id'],
            'work_date' => $now->toDateString(),
            'break_minutes' => $weekdaySettings['default_break_minutes'] ?? $targetUser->default_break_minutes,
            'status' => 'working',
        ]);

        $currentTime = $now->format('H:i');

        if ($data['type'] === 'in') {
            $record->fill([
                'clock_in' => $record->clock_in ?: $currentTime,
                'clock_in_recorded_at' => $record->clock_in_recorded_at ?? $now,
                'status' => 'working',
            ]);
        } else {
            $workLocation = $data['work_location'] ?? $record->work_location ?? 'office';
            $record->fill([
                'clock_out' => $currentTime,
                'clock_out_recorded_at' => $now,
                'declared_clock_in' => $data['declared_clock_in'] ?? $record->declared_clock_in ?? $weekdaySettings['default_clock_in'] ?? $targetUser->default_clock_in,
                'declared_clock_out' => $data['declared_clock_out'] ?? $record->declared_clock_out ?? $weekdaySettings['default_clock_out'] ?? $targetUser->default_clock_out,
                'declared_break_minutes' => $data['declared_break_minutes'] ?? $record->declared_break_minutes ?? $weekdaySettings['default_break_minutes'] ?? $targetUser->default_break_minutes,
                'work_location' => $workLocation,
                'meal_percentage' => $workLocation === 'home' ? null : ($data['meal_percentage'] ?? $record->meal_percentage),
                'missed_meal' => $workLocation === 'home' ? false : (bool) ($data['missed_meal'] ?? $record->missed_meal ?? false),
                'status' => 'completed',
            ]);
        }

        $record->save();

        return response()->json($this->serializeRecord($record->load('user:id,name,email'), $request->user()));
    }

    public function cancelClock(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', Rule::in(['in', 'out'])],
        ]);
        $this->authorizeUserAccess($request, (int) $data['user_id']);

        $record = AttendanceRecord::query()
            ->where('user_id', $data['user_id'])
            ->whereDate('work_date', now()->toDateString())
            ->firstOrFail();

        if ($data['type'] === 'out') {
            abort_unless($this->canCancelClockOut($record), 422, '退勤打刻は5分以内のみ取消できます。');

            $record->update([
                'clock_out' => null,
                'clock_out_recorded_at' => null,
                'declared_clock_in' => null,
                'declared_clock_out' => null,
                'declared_break_minutes' => null,
                'work_location' => null,
                'meal_percentage' => null,
                'missed_meal' => false,
                'status' => 'working',
            ]);

            return response()->json($this->serializeRecord($record->load('user:id,name,email'), $request->user()));
        }

        abort_unless($this->canCancelClockIn($record), 422, '出勤打刻は5分以内のみ取消できます。');

        if (blank($record->note)) {
            $record->delete();

            return response()->json([
                'cancelled' => true,
                'deleted' => true,
            ]);
        }

        $record->update([
            'clock_in' => null,
            'clock_in_recorded_at' => null,
            'status' => 'not_clocked',
        ]);

        return response()->json($this->serializeRecord($record->load('user:id,name,email'), $request->user()));
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        if (! $request->user()->isAdmin()) {
            $request->merge(['user_id' => $request->user()->id]);
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'work_date' => [
                'required',
                'date',
                Rule::unique('attendance_records')
                    ->where(fn ($query) => $query->where('user_id', $request->integer('user_id')))
                    ->ignore($ignoreId),
            ],
            'clock_in' => ['nullable', 'date_format:H:i'],
            'clock_out' => ['nullable', 'date_format:H:i', 'after_or_equal:clock_in'],
            'declared_clock_in' => ['nullable', 'date_format:H:i'],
            'declared_clock_out' => ['nullable', 'date_format:H:i', 'after_or_equal:declared_clock_in'],
            'declared_break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'work_location' => ['nullable', Rule::in(['office', 'home'])],
            'meal_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
            'missed_meal' => ['nullable', 'boolean'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'status' => ['required', Rule::in(['working', 'completed', 'not_clocked', 'holiday', 'absence', 'paid_leave', 'planned_vacation', 'morning_paid_leave', 'afternoon_paid_leave', 'late', 'early_leave', 'late_and_early_leave', 'business_support'])],
            'request_reason_category' => ['nullable', Rule::in(self::REQUEST_REASON_CATEGORIES)],
            'request_reason' => ['nullable', 'string', 'max:1000'],
            'request_absent_start_time' => ['nullable', 'date_format:H:i'],
            'request_absent_end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:request_absent_start_time'],
            'request_late_start_time' => ['nullable', 'date_format:H:i'],
            'request_late_end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:request_late_start_time'],
            'request_early_leave_start_time' => ['nullable', 'date_format:H:i'],
            'request_early_leave_end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:request_early_leave_start_time'],
            'note' => ['nullable', 'string', 'max:1000'],
            'admin_comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $request->user()->isAdmin()) {
            unset($data['admin_comment']);
        }

        $this->normalizeMealFields($data);

        return $data;
    }

    private function normalizeMealFields(array &$data): void
    {
        if (($data['work_location'] ?? null) === 'home') {
            $data['meal_percentage'] = null;
            $data['missed_meal'] = false;

            return;
        }

        $data['meal_percentage'] = array_key_exists('meal_percentage', $data) ? $data['meal_percentage'] : null;
        $data['missed_meal'] = (bool) ($data['missed_meal'] ?? false);
    }

    private function pullAttendanceRequestData(array &$data): array
    {
        $requestData = [
            'reason_category' => $data['request_reason_category'] ?? null,
            'reason' => $data['request_reason'] ?? null,
            'start_time' => $data['request_absent_start_time'] ?? null,
            'end_time' => $data['request_absent_end_time'] ?? null,
            'late_start_time' => $data['request_late_start_time'] ?? null,
            'late_end_time' => $data['request_late_end_time'] ?? null,
            'early_leave_start_time' => $data['request_early_leave_start_time'] ?? null,
            'early_leave_end_time' => $data['request_early_leave_end_time'] ?? null,
        ];

        unset(
            $data['request_reason_category'],
            $data['request_reason'],
            $data['request_absent_start_time'],
            $data['request_absent_end_time'],
            $data['request_late_start_time'],
            $data['request_late_end_time'],
            $data['request_early_leave_start_time'],
            $data['request_early_leave_end_time'],
        );

        return $requestData;
    }

    private function syncAttendanceRequestsForRecordStatus(AttendanceRecord $record, array $requestData): void
    {
        $types = $this->attendanceRequestTypesForRecordStatus($record->status);
        $syncableTypes = ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave'];

        AttendanceRequest::query()
            ->where('user_id', $record->user_id)
            ->whereDate('request_date', $record->work_date->format('Y-m-d'))
            ->whereIn('type', array_diff($syncableTypes, $types))
            ->get()
            ->each(function (AttendanceRequest $request): void {
                app(PaidLeaveAdjuster::class)->removeForAttendanceRequest($request);
                $request->delete();
            });

        foreach ($types as $type) {
            $attributes = [
                'user_id' => $record->user_id,
                'type' => $type,
                'request_date' => $record->work_date->format('Y-m-d'),
            ];
            $request = AttendanceRequest::query()->firstOrNew($attributes);

            $request->fill([
                'reason_category' => $requestData['reason_category'] ?: $request->reason_category ?: '私用のため',
                'reason' => $requestData['reason'] ?? $request->reason,
                'status' => $request->status ?: 'pending',
                'admin_checked' => $request->exists ? $request->admin_checked : false,
                'service_manager_checked' => $request->exists ? $request->service_manager_checked : false,
                'applied_attendance_record_id' => $record->id,
            ]);

            $startTime = $requestData["{$type}_start_time"] ?? $requestData['start_time'] ?? null;
            $endTime = $requestData["{$type}_end_time"] ?? $requestData['end_time'] ?? null;
            if ($startTime !== null) {
                $request->start_time = $startTime ?: null;
            }
            if ($endTime !== null) {
                $request->end_time = $endTime ?: null;
            }

            if ($type === 'business_support') {
                $request->start_time = $request->start_time ?: '10:00';
                $request->end_time = $request->end_time ?: '11:00';
            }

            $request->save();
            app(PaidLeaveAdjuster::class)->syncForAttendanceRequest($request);
        }
    }

    private function requestsForRecord(AttendanceRecord $record)
    {
        return AttendanceRequest::query()
            ->where('user_id', $record->user_id)
            ->whereDate('request_date', $record->work_date->format('Y-m-d'))
            ->whereIn('type', ['absence', 'late', 'early_leave', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave', 'business_support'])
            ->get();
    }

    private function attendanceRequestTypesForRecordStatus(string $status): array
    {
        return match ($status) {
            'absence' => ['absence'],
            'late' => ['late'],
            'early_leave' => ['early_leave'],
            'late_and_early_leave' => ['late', 'early_leave'],
            'paid_leave' => ['paid_leave'],
            'morning_paid_leave' => ['morning_paid_leave'],
            'afternoon_paid_leave' => ['afternoon_paid_leave'],
            default => [],
        };
    }

    private function attendanceRequestDetails($requests): array
    {
        $request = $requests
            ->sortBy(fn (AttendanceRequest $request) => filled($request->reason_category) || filled($request->reason) ? 0 : 1)
            ->first();
        $lateRequest = $requests->firstWhere('type', 'late');
        $earlyLeaveRequest = $requests->firstWhere('type', 'early_leave');
        $timeRequest = $lateRequest ?? $earlyLeaveRequest ?? $request;

        return [
            'reason_category' => $request?->reason_category ?? '',
            'reason' => $request?->reason ?? '',
            'start_time' => $timeRequest?->start_time ? substr($timeRequest->start_time, 0, 5) : '',
            'end_time' => $timeRequest?->end_time ? substr($timeRequest->end_time, 0, 5) : '',
            'late_start_time' => $lateRequest?->start_time ? substr($lateRequest->start_time, 0, 5) : '',
            'late_end_time' => $lateRequest?->end_time ? substr($lateRequest->end_time, 0, 5) : '',
            'early_leave_start_time' => $earlyLeaveRequest?->start_time ? substr($earlyLeaveRequest->start_time, 0, 5) : '',
            'early_leave_end_time' => $earlyLeaveRequest?->end_time ? substr($earlyLeaveRequest->end_time, 0, 5) : '',
        ];
    }

    private function serializeAttendanceRequests($requests): array
    {
        return $requests
            ->values()
            ->map(fn (AttendanceRequest $request) => [
                'id' => $request->id,
                'type' => $request->type,
                'request_date' => $request->request_date->format('Y-m-d'),
                'admin_checked' => (bool) $request->admin_checked,
                'service_manager_checked' => (bool) $request->service_manager_checked,
            ])
            ->all();
    }

    private function authorizeUserAccess(Request $request, int $userId): void
    {
        abort_unless($request->user()->isAdmin() || $request->user()->id === $userId, 403);
    }

    private function authorizeEditableDate(Request $request, string $workDate): void
    {
        abort_unless($request->user()->isAdmin() || $this->isEditableDate($workDate), 403, '直近3日以内の勤怠のみ修正できます。');
    }

    private function isEditableDate(string $workDate): bool
    {
        $date = Carbon::parse($workDate)->startOfDay();
        $oldestEditableDate = now()->startOfDay()->subDays(2);

        return $date->betweenIncluded($oldestEditableDate, now()->startOfDay());
    }

    private function serializeRecord(AttendanceRecord $record, User $viewer, $requests = null): array
    {
        $requests = $requests ?? collect();
        $clockIn = $record->clock_in ? Carbon::parse($record->clock_in) : null;
        $clockOut = $record->clock_out ? Carbon::parse($record->clock_out) : null;
        $workDate = $record->work_date->format('Y-m-d');
        $workedMinutes = $clockIn && $clockOut
            ? max(0, $clockIn->diffInMinutes($clockOut) - $record->break_minutes)
            : 0;
        $isPlannedVacation = $this->isPlannedVacationDate($workDate, $record->user);
        $isNonWorkingDay = $this->isNonWorkingDate($workDate, $record->user);
        $status = $this->normalizedDisplayRecordStatus($record, $isNonWorkingDay);
        $displayStatus = $this->displayStatus($record, $requests, $status);
        $requestDetails = $this->attendanceRequestDetails($requests);
        $calendarStatus = $this->calendarDisplayStatus($workDate, $record->user, $record);
        if ($calendarStatus) {
            $displayStatus = $calendarStatus;
        }

        return [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'employee' => $record->user?->name,
            'work_date' => $workDate,
            'clock_in' => $record->clock_in ? substr($record->clock_in, 0, 5) : '',
            'clock_out' => $record->clock_out ? substr($record->clock_out, 0, 5) : '',
            'declared_clock_in' => $record->declared_clock_in ? substr($record->declared_clock_in, 0, 5) : '',
            'declared_clock_out' => $record->declared_clock_out ? substr($record->declared_clock_out, 0, 5) : '',
            'declared_break_minutes' => $record->declared_break_minutes,
            'work_location' => $record->work_location ?? '',
            'work_location_label' => $this->workLocationLabel($record->work_location),
            'meal_percentage' => $record->meal_percentage,
            'missed_meal' => (bool) $record->missed_meal,
            'break_minutes' => $record->break_minutes,
            'status' => $status,
            'display_status' => $displayStatus['label'],
            'display_status_type' => $displayStatus['type'],
            'has_attendance_request' => $requests->isNotEmpty(),
            'attendance_request_types' => $requests->pluck('type')->values()->all(),
            'attendance_requests' => $this->serializeAttendanceRequests($requests),
            'request_reason_category' => $requestDetails['reason_category'],
            'request_reason' => $requestDetails['reason'],
            'request_absent_start_time' => $requestDetails['start_time'],
            'request_absent_end_time' => $requestDetails['end_time'],
            'request_late_start_time' => $requestDetails['late_start_time'],
            'request_late_end_time' => $requestDetails['late_end_time'],
            'request_early_leave_start_time' => $requestDetails['early_leave_start_time'],
            'request_early_leave_end_time' => $requestDetails['early_leave_end_time'],
            'is_planned_vacation' => $isPlannedVacation,
            'note' => $record->note ?? '',
            'admin_comment' => $this->adminCommentForRecord($record),
            'worked_minutes' => $workedMinutes,
            'is_non_working_day' => $isNonWorkingDay,
            'can_edit' => $viewer->isAdmin() || $this->isEditableDate($workDate),
            'can_report_edit' => $this->isEditableDate($workDate),
        ];
    }

    private function clockStatus(User $user): array
    {
        $today = now()->toDateString();
        $record = AttendanceRecord::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $today)
            ->first();
        $blockedReason = $this->clockInBlockedReason($user, $record, $today);
        $clockOutBlockedReason = $this->clockOutBlockedReason($record);

        return [
            'can_clock_in' => $blockedReason === null,
            'clock_in_disabled_reason' => $blockedReason,
            'can_clock_out' => $clockOutBlockedReason === null,
            'clock_out_disabled_reason' => $clockOutBlockedReason,
            'can_cancel_clock_in' => $record ? $this->canCancelClockIn($record) : false,
            'can_cancel_clock_out' => $record ? $this->canCancelClockOut($record) : false,
        ];
    }

    private function canCancelClockIn(AttendanceRecord $record): bool
    {
        return $record->clock_in
            && ! $record->clock_out
            && $record->clock_in_recorded_at
            && $record->clock_in_recorded_at->greaterThanOrEqualTo(now()->subMinutes(5));
    }

    private function canCancelClockOut(AttendanceRecord $record): bool
    {
        return $record->clock_out
            && $record->clock_out_recorded_at
            && $record->clock_out_recorded_at->greaterThanOrEqualTo(now()->subMinutes(5));
    }

    private function clockOutBlockedReason(?AttendanceRecord $record): ?string
    {
        if (! $record?->clock_in) {
            return '出勤打刻がないため退勤できません。';
        }

        if ($record->clock_out) {
            return 'すでに退勤済みです。';
        }

        if (blank($record->note)) {
            return '業務報告を入力してから退勤してください。';
        }

        return null;
    }

    private function clockInBlockedReason(User $user, ?AttendanceRecord $record, string $date): ?string
    {
        if ($record?->clock_in) {
            return 'すでに出勤しています。';
        }

        if ($this->isPlannedVacationDate($date, $user)) {
            return '計画有給日のため出勤できません。';
        }

        if ($this->isFreeAttendanceDate($date, $user)) {
            return null;
        }

        if ($this->isNonWorkingDate($date, $user)) {
            return '休日のため出勤できません。';
        }

        $hasPaidLeaveRequest = AttendanceRequest::query()
            ->where('user_id', $user->id)
            ->whereDate('request_date', $date)
            ->where('type', 'paid_leave')
            ->exists();

        if ($hasPaidLeaveRequest) {
            return '有給の届出があるため出勤できません。';
        }

        return null;
    }

    private function serializeEmptyTodayRecord(User $user, User $viewer, string $today, $requests = null): array
    {
        $requests = $requests ?? collect();
        $weekdaySettings = $user->normalizedWorkdaySettings()[(string) Carbon::parse($today)->dayOfWeekIso] ?? null;
        $isNonWorkingDay = $this->isNonWorkingDate($today, $user);
        $displayStatus = $this->displayStatus(null, $requests);
        $requestDetails = $this->attendanceRequestDetails($requests);
        $isPlannedVacation = $this->isPlannedVacationDate($today, $user);
        $calendarStatus = $this->calendarDisplayStatus($today, $user);
        if ($calendarStatus) {
            $displayStatus = $calendarStatus;
        }

        return [
            'id' => null,
            'user_id' => $user->id,
            'employee' => $user->name,
            'work_date' => $today,
            'clock_in' => '',
            'clock_out' => '',
            'declared_clock_in' => '',
            'declared_clock_out' => '',
            'declared_break_minutes' => '',
            'work_location' => '',
            'work_location_label' => '',
            'meal_percentage' => null,
            'missed_meal' => false,
            'break_minutes' => $isNonWorkingDay ? 0 : ($weekdaySettings['default_break_minutes'] ?? $user->default_break_minutes ?? 60),
            'status' => $isNonWorkingDay ? 'holiday' : 'not_clocked',
            'display_status' => $displayStatus['label'] ?? ($isNonWorkingDay ? '休日' : '未打刻'),
            'display_status_type' => $displayStatus['label'] ? $displayStatus['type'] : ($isNonWorkingDay ? 'holiday' : 'not_clocked'),
            'has_attendance_request' => $requests->isNotEmpty(),
            'attendance_request_types' => $requests->pluck('type')->values()->all(),
            'attendance_requests' => $this->serializeAttendanceRequests($requests),
            'request_reason_category' => $requestDetails['reason_category'],
            'request_reason' => $requestDetails['reason'],
            'request_absent_start_time' => $requestDetails['start_time'],
            'request_absent_end_time' => $requestDetails['end_time'],
            'request_late_start_time' => $requestDetails['late_start_time'],
            'request_late_end_time' => $requestDetails['late_end_time'],
            'request_early_leave_start_time' => $requestDetails['early_leave_start_time'],
            'request_early_leave_end_time' => $requestDetails['early_leave_end_time'],
            'is_planned_vacation' => $isPlannedVacation,
            'note' => '',
            'admin_comment' => '',
            'worked_minutes' => 0,
            'is_non_working_day' => $isNonWorkingDay,
            'can_edit' => $viewer->isAdmin(),
            'can_report_edit' => false,
        ];
    }

    private function serializeEmptyHistoryRecord(User $user, string $workDate, $requests = null): array
    {
        $requests = $requests ?? collect();
        $isNonWorkingDay = $this->isNonWorkingDate($workDate, $user);
        $displayStatus = $this->displayStatus(null, $requests);
        $requestDetails = $this->attendanceRequestDetails($requests);
        $isPlannedVacation = $this->isPlannedVacationDate($workDate, $user);
        $calendarStatus = $this->calendarDisplayStatus($workDate, $user);
        if ($calendarStatus) {
            $displayStatus = $calendarStatus;
        }

        return [
            'id' => null,
            'user_id' => $user->id,
            'employee' => $user->name,
            'work_date' => $workDate,
            'clock_in' => '',
            'clock_out' => '',
            'declared_clock_in' => '',
            'declared_clock_out' => '',
            'declared_break_minutes' => '',
            'work_location' => '',
            'work_location_label' => '',
            'meal_percentage' => null,
            'missed_meal' => false,
            'break_minutes' => $isNonWorkingDay ? 0 : '',
            'status' => $isNonWorkingDay ? 'holiday' : '',
            'display_status' => $displayStatus['label'] ?? ($isNonWorkingDay ? '休日' : ''),
            'display_status_type' => $displayStatus['label'] ? $displayStatus['type'] : ($isNonWorkingDay ? 'holiday' : ''),
            'has_attendance_request' => $requests->isNotEmpty(),
            'attendance_request_types' => $requests->pluck('type')->values()->all(),
            'attendance_requests' => $this->serializeAttendanceRequests($requests),
            'request_reason_category' => $requestDetails['reason_category'],
            'request_reason' => $requestDetails['reason'],
            'request_absent_start_time' => $requestDetails['start_time'],
            'request_absent_end_time' => $requestDetails['end_time'],
            'request_late_start_time' => $requestDetails['late_start_time'],
            'request_late_end_time' => $requestDetails['late_end_time'],
            'request_early_leave_start_time' => $requestDetails['early_leave_start_time'],
            'request_early_leave_end_time' => $requestDetails['early_leave_end_time'],
            'is_planned_vacation' => $isPlannedVacation,
            'note' => '',
            'admin_comment' => '',
            'worked_minutes' => 0,
            'is_non_working_day' => $isNonWorkingDay,
            'can_edit' => true,
            'can_report_edit' => false,
        ];
    }

    private function pdfAttendanceRow(User $user, Carbon $date, ?AttendanceRecord $record, $requests): array
    {
        $dateKey = $date->format('Y-m-d');
        $weekdaySettings = $user->normalizedWorkdaySettings()[(string) $date->dayOfWeekIso] ?? null;
        $isNonWorkingDay = $this->isNonWorkingDate($dateKey, $user);
        $statusKey = $this->pdfStatusKey($user, $dateKey, $record, $requests, $isNonWorkingDay);
        $shiftClockIn = $isNonWorkingDay ? '' : ($weekdaySettings['default_clock_in'] ?? substr((string) $user->default_clock_in, 0, 5) ?: '');
        $shiftClockOut = $isNonWorkingDay ? '' : ($weekdaySettings['default_clock_out'] ?? substr((string) $user->default_clock_out, 0, 5) ?: '');
        $shiftBreakMinutes = $isNonWorkingDay ? 0 : (int) ($weekdaySettings['default_break_minutes'] ?? $user->default_break_minutes ?? 0);
        $declaredClockIn = $record?->declared_clock_in ? substr($record->declared_clock_in, 0, 5) : '';
        $declaredClockOut = $record?->declared_clock_out ? substr($record->declared_clock_out, 0, 5) : '';
        $declaredBreakMinutes = (int) ($record?->declared_break_minutes ?? 0);
        $workRangeMinutes = $this->timeRangeMinutes($declaredClockIn, $declaredClockOut);
        $workedMinutes = $workRangeMinutes === null ? 0 : max(0, $workRangeMinutes - $declaredBreakMinutes);
        $scheduledOverlapMinutes = $this->overlapMinutes($declaredClockIn, $declaredClockOut, $shiftClockIn, $shiftClockOut);
        $withinMinutes = $workedMinutes > 0
            ? max(0, min($workedMinutes, $scheduledOverlapMinutes - min($declaredBreakMinutes, $scheduledOverlapMinutes)))
            : 0;
        if ($workedMinutes > 0 && $scheduledOverlapMinutes === 0) {
            $withinMinutes = $shiftClockIn && $shiftClockOut ? 0 : $workedMinutes;
        }
        $overtimeMinutes = max(0, $workedMinutes - $withinMinutes);
        $lateMinutes = $this->timeRangeMinutes($shiftClockIn, $declaredClockIn) ?? 0;
        $earlyLeaveMinutes = $this->timeRangeMinutes($declaredClockOut, $shiftClockOut) ?? 0;

        if (! in_array($statusKey, ['late', 'late_and_early_leave'], true)) {
            $lateMinutes = 0;
        }
        if (! in_array($statusKey, ['early_leave', 'late_and_early_leave'], true)) {
            $earlyLeaveMinutes = 0;
        }

        return [
            'day' => $date->day,
            'weekday' => ['日', '月', '火', '水', '木', '金', '土'][$date->dayOfWeek],
            'weekday_key' => $date->dayOfWeek,
            'status' => $this->pdfStatusLabel($statusKey),
            'status_key' => $statusKey,
            'shift_clock_in' => $shiftClockIn,
            'shift_clock_out' => $shiftClockOut,
            'clock_in' => $declaredClockIn,
            'clock_out' => $declaredClockOut,
            'within_minutes' => $withinMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'declared_break_minutes' => $workedMinutes > 0 ? $declaredBreakMinutes : 0,
            'late_minutes' => max(0, $lateMinutes),
            'early_leave_minutes' => max(0, $earlyLeaveMinutes),
            'note' => $record?->admin_comment ?? '',
        ];
    }

    private function companyPdfAttendanceRow(array $baseRow, ?AttendanceRecord $record, User $user): array
    {
        $workTime = '-';
        if ($baseRow['clock_in'] && $baseRow['clock_out']) {
            $workTime = "{$baseRow['clock_in']}〜{$baseRow['clock_out']}\n(休{$baseRow['declared_break_minutes']}分)";
        }

        return [
            ...$baseRow,
            'work_location' => $this->pdfWorkLocationLabel($record?->work_location),
            'confirmed' => $baseRow['status_key'] === 'attendance' ? '✓' : '-',
            'work_time' => $workTime,
            'business_report' => $record?->note ?? '',
            'admin_comment' => $record?->admin_comment ?? '',
            'bmi' => $this->bmiLabel($user),
        ];
    }

    private function makePdf(string $format = 'A4-L', int $marginLeft = 8, int $marginRight = 8, int $marginTop = 8, int $marginBottom = 8): Mpdf
    {
        return new Mpdf([
            'mode' => 'ja',
            'format' => $format,
            'margin_left' => $marginLeft,
            'margin_right' => $marginRight,
            'margin_top' => $marginTop,
            'margin_bottom' => $marginBottom,
            'fontDir' => array_merge((new \Mpdf\Config\ConfigVariables)->getDefaults()['fontDir'], [
                'C:/Windows/Fonts',
            ]),
            'fontdata' => array_merge((new \Mpdf\Config\FontVariables)->getDefaults()['fontdata'], [
                'notosansjp' => [
                    'R' => 'NotoSansJP-VF.ttf',
                    'B' => 'NotoSansJP-VF.ttf',
                ],
                'msgothic' => [
                    'R' => 'msgothic.ttc',
                    'B' => 'msgothic.ttc',
                    'TTCfontID' => [
                        'R' => 1,
                        'B' => 1,
                    ],
                ],
            ]),
            'default_font' => 'notosansjp',
        ]);
    }

    private function pdfWorkLocationLabel(?string $workLocation): string
    {
        return match ($workLocation) {
            'office' => '通所',
            'home' => '在宅',
            default => '-',
        };
    }

    private function bmiLabel(User $user): string
    {
        if (! $user->height_cm || ! $user->weight_kg || (float) $user->height_cm <= 0) {
            return '-';
        }

        $height = ((float) $user->height_cm) / 100;

        return number_format(((float) $user->weight_kg) / ($height * $height), 1);
    }

    private function pdfStatusKey(User $user, string $date, ?AttendanceRecord $record, $requests, bool $isNonWorkingDay): string
    {
        if ($requests->contains('type', 'business_support') || $record?->status === 'business_support') {
            return 'attendance';
        }
        if ($requests->contains('type', 'absence') || $record?->status === 'absence') {
            return 'absence';
        }
        if ($requests->contains('type', 'paid_leave') || $record?->status === 'paid_leave') {
            return 'paid_leave';
        }
        if ($this->isPlannedVacationDate($date, $user) || $record?->status === 'planned_vacation') {
            return 'planned_vacation';
        }
        if ($requests->contains('type', 'morning_paid_leave') || $record?->status === 'morning_paid_leave') {
            return 'morning_paid_leave';
        }
        if ($requests->contains('type', 'afternoon_paid_leave') || $record?->status === 'afternoon_paid_leave') {
            return 'afternoon_paid_leave';
        }
        if (($requests->contains('type', 'late') && $requests->contains('type', 'early_leave')) || $record?->status === 'late_and_early_leave') {
            return 'late_and_early_leave';
        }
        if ($requests->contains('type', 'late') || $record?->status === 'late') {
            return 'late';
        }
        if ($requests->contains('type', 'early_leave') || $record?->status === 'early_leave') {
            return 'early_leave';
        }
        if ($isNonWorkingDay || $record?->status === 'holiday') {
            return 'holiday';
        }
        if ($record && ($record->clock_in || $record->clock_out || in_array($record->status, ['working', 'completed'], true))) {
            return 'attendance';
        }

        return '';
    }

    private function pdfStatusLabel(string $status): string
    {
        return match ($status) {
            'attendance' => '出勤',
            'holiday' => '公休',
            'absence' => '欠勤',
            'paid_leave' => '有給',
            'planned_vacation' => '計画有給',
            'morning_paid_leave' => '前半有給',
            'afternoon_paid_leave' => '後半有給',
            'late' => '遅刻',
            'early_leave' => '早退',
            'late_and_early_leave' => '遅刻早退',
            default => '-',
        };
    }

    private function timeRangeMinutes(?string $start, ?string $end): ?int
    {
        if (! $start || ! $end) {
            return null;
        }

        return (int) Carbon::createFromFormat('H:i', $start)->diffInMinutes(Carbon::createFromFormat('H:i', $end), false);
    }

    private function overlapMinutes(?string $firstStart, ?string $firstEnd, ?string $secondStart, ?string $secondEnd): int
    {
        if (! $firstStart || ! $firstEnd || ! $secondStart || ! $secondEnd) {
            return 0;
        }

        $firstStartMinutes = $this->minutesFromTime($firstStart);
        $firstEndMinutes = $this->minutesFromTime($firstEnd);
        $secondStartMinutes = $this->minutesFromTime($secondStart);
        $secondEndMinutes = $this->minutesFromTime($secondEnd);

        return max(0, min($firstEndMinutes, $secondEndMinutes) - max($firstStartMinutes, $secondStartMinutes));
    }

    private function minutesFromTime(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    private function reiwaYear(int $year): int
    {
        return $year - 2018;
    }

    private function serializeBusinessReport(User $user, string $workDate, string $note, string $adminComment = ''): array
    {
        return [
            'user_id' => $user->id,
            'employee' => $user->name,
            'work_date' => $workDate,
            'note' => $note,
            'admin_comment' => filled($adminComment) ? $adminComment : $this->generateAdminReportComment($note),
        ];
    }

    private function adminCommentForRecord(AttendanceRecord $record): string
    {
        if (filled($record->admin_comment)) {
            return $record->admin_comment;
        }

        if (blank($record->note)) {
            return '';
        }

        $comment = $this->generateAdminReportComment($record->note ?? '');
        $record->forceFill(['admin_comment' => $comment])->save();

        return $comment;
    }

    private function generateAdminReportComment(string $note): string
    {
        $note = trim($note);

        if ($note === '') {
            return $this->fallbackAdminReportComment($note);
        }

        if (app()->environment('testing') || blank(config('services.openrouter.key'))) {
            return $this->fallbackAdminReportComment($note);
        }

        $cacheKey = 'business-report-comment:'.hash('sha256', config('services.openrouter.model').'|'.$note);

        return Cache::remember($cacheKey, now()->addDays(7), function () use ($note) {
            try {
                $response = Http::withToken(config('services.openrouter.key'))
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders(array_filter([
                        'HTTP-Referer' => config('services.openrouter.referer'),
                        'X-Title' => config('services.openrouter.title'),
                    ]))
                    ->timeout(12)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => config('services.openrouter.model'),
                        'temperature' => 0.6,
                        'max_tokens' => 120,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'あなたは就労支援施設の管理者です。利用者の日報に対して、自然で前向きな管理者コメントを日本語で1文から2文だけ返してください。個人情報や医療的断定は避け、ねぎらい、確認、次回への軽い提案を含めます。引用符や箇条書きは不要です。',
                            ],
                            [
                                'role' => 'user',
                                'content' => "業務報告:\n{$note}",
                            ],
                        ],
                    ]);

                if (! $response->successful()) {
                    Log::warning('OpenRouter comment generation failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return $this->fallbackAdminReportComment($note);
                }

                $comment = trim((string) data_get($response->json(), 'choices.0.message.content'));

                return $comment !== '' ? mb_substr($comment, 0, 220) : $this->fallbackAdminReportComment($note);
            } catch (\Throwable $exception) {
                Log::warning('OpenRouter comment generation exception.', [
                    'message' => $exception->getMessage(),
                ]);

                return $this->fallbackAdminReportComment($note);
            }
        });
    }

    private function fallbackAdminReportComment(string $note): string
    {
        $note = trim($note);

        if ($note === '') {
            return '業務報告が未入力です。入力後に内容を確認します。';
        }

        if (str_contains($note, '顧客') || str_contains($note, '対応')) {
            return '顧客対応の状況が具体的に共有されています。次回も対応内容と結果をセットで残せると、さらに振り返りやすくなります。';
        }

        if (str_contains($note, '遅') || str_contains($note, '残業') || str_contains($note, '時間')) {
            return '時間に関する変化が確認できました。無理のない進め方を意識しつつ、必要があれば早めに共有してください。';
        }

        if (str_contains($note, '確認') || str_contains($note, '整理') || str_contains($note, '入力')) {
            return '確認・整理系の作業が丁寧に進められています。抜け漏れを防ぐ意識が見えていて良い報告です。';
        }

        if (mb_strlen($note) >= 40) {
            return '本日の取り組みがしっかり記録されています。作業内容が伝わる報告なので、次は成果や気づきも添えるとより良くなります。';
        }

        return '本日の業務内容を確認しました。次回は作業の結果や困った点も一言添えると、より状況が伝わりやすくなります。';
    }

    private function displayStatus(?AttendanceRecord $record, $requests, ?string $displayStatus = null): array
    {
        $status = $displayStatus ?? ($record ? $this->normalizedRecordStatus($record) : null);
        $hasLateRequest = $requests->contains('type', 'late');
        $hasEarlyLeaveRequest = $requests->contains('type', 'early_leave');

        if ($record) {
            return ['label' => null, 'type' => $status];
        }

        if ($requests->contains('type', 'business_support')) {
            return ['label' => '業務対応', 'type' => 'business_support'];
        }

        if ($requests->contains('type', 'absence')) {
            return ['label' => '欠勤', 'type' => 'absence'];
        }

        if ($requests->contains('type', 'paid_leave')) {
            return ['label' => '有給', 'type' => 'paid_leave'];
        }

        if ($status === 'planned_vacation') {
            return ['label' => '計画有給', 'type' => 'planned_vacation'];
        }

        if ($requests->contains('type', 'morning_paid_leave')) {
            return ['label' => '前半有給', 'type' => 'morning_paid_leave'];
        }

        if ($requests->contains('type', 'afternoon_paid_leave')) {
            return ['label' => '後半有給', 'type' => 'afternoon_paid_leave'];
        }

        if ($hasLateRequest && $hasEarlyLeaveRequest) {
            return ['label' => '遅刻かつ早退', 'type' => 'late_and_early_leave'];
        }

        if ($status === 'late_and_early_leave') {
            return ['label' => null, 'type' => 'late_and_early_leave'];
        }

        if ($hasLateRequest) {
            return ['label' => '遅刻', 'type' => 'late'];
        }

        if ($hasEarlyLeaveRequest) {
            return [
                'label' => $record?->clock_out ? '早退済' : '早退予定',
                'type' => $record?->clock_out ? 'early_leave_done' : 'early_leave_planned',
            ];
        }

        return ['label' => null, 'type' => $status];
    }

    private function normalizedRecordStatus(AttendanceRecord $record): string
    {
        if ($record->status === 'working' && $record->clock_out) {
            return 'completed';
        }

        return $record->status;
    }

    private function workLocationLabel(?string $workLocation): string
    {
        return match ($workLocation) {
            'office' => '通所',
            'home' => '在宅',
            default => '',
        };
    }

    private function calendarDisplayStatus(string $date, ?User $user, ?AttendanceRecord $record = null): ?array
    {
        if ($this->isPlannedVacationDate($date, $user)) {
            return ['label' => '計画有給', 'type' => 'planned_vacation'];
        }

        if ($this->isFreeAttendanceDate($date, $user)) {
            return ['label' => '自由出勤日', 'type' => 'free_attendance'];
        }

        return null;
    }

    private function hasCalendarEntry(string $date, string $type): bool
    {
        $key = "{$date}|{$type}";
        if (array_key_exists($key, $this->calendarEntryDateTypeCache)) {
            return $this->calendarEntryDateTypeCache[$key];
        }

        return $this->calendarEntryDateTypeCache[$key] = CalendarEntry::query()
            ->whereDate('date', $date)
            ->where('type', $type)
            ->exists();
    }

    private function isFreeAttendanceDate(string $date, ?User $user = null): bool
    {
        if (! $user || $user->isEffectivelyRetired()) {
            return false;
        }

        $carbon = Carbon::parse($date);
        if ($carbon->isSaturday() && ! $this->hasCalendarEntry($date, 'saturday_work')) {
            return false;
        }

        return match ($user->commute_limit_days) {
            '-8日' => $this->hasCalendarEntry($date, 'free_attendance_8'),
            '-4日' => $this->hasCalendarEntry($date, 'free_attendance_4'),
            default => false,
        };
    }

    private function isPlannedVacationDate(string $date, ?User $user = null): bool
    {
        return $user
            && ! $user->isEffectivelyRetired()
            && $this->hasCalendarEntry($date, 'planned_vacation');
    }

    private function isScheduledWorkingDate(string $date, User $user): bool
    {
        $carbon = Carbon::parse($date);

        if ($user->isEffectivelyRetired()) {
            return false;
        }

        if ($this->hasCalendarEntry($date, 'holiday_off')) {
            return false;
        }

        if ($carbon->isSaturday() && $this->hasCalendarEntry($date, 'saturday_work')) {
            return true;
        }

        if ($carbon->isSaturday()) {
            return false;
        }

        if ($carbon->isSunday() || in_array($carbon->toDateString(), $this->japanesePublicHolidays((int) $carbon->year), true)) {
            return false;
        }

        $weekdaySetting = $user->normalizedWorkdaySettings()[(string) $carbon->dayOfWeekIso] ?? null;

        return $weekdaySetting ? (bool) $weekdaySetting['is_working_day'] : ! $carbon->isSaturday();
    }

    private function isNonWorkingDate(string $date, ?User $user = null): bool
    {
        $carbon = Carbon::parse($date);

        if ($this->isPlannedVacationDate($date, $user)) {
            return false;
        }

        if ($this->hasCalendarEntry($date, 'holiday_off')) {
            return true;
        }

        if ($carbon->isSaturday() && $this->hasCalendarEntry($date, 'saturday_work')) {
            return false;
        }

        if ($carbon->isSaturday()) {
            return true;
        }

        if ($carbon->isSunday() || in_array($carbon->toDateString(), $this->japanesePublicHolidays((int) $carbon->year), true)) {
            return true;
        }

        if (! $user) {
            return $carbon->isSaturday();
        }

        $weekdaySetting = $user->normalizedWorkdaySettings()[(string) $carbon->dayOfWeekIso] ?? null;

        return $weekdaySetting && ! $weekdaySetting['is_working_day'];
    }

    private function normalizedDisplayRecordStatus(AttendanceRecord $record, bool $isNonWorkingDay): string
    {
        $status = $this->normalizedRecordStatus($record);

        if ($status !== 'holiday' || $isNonWorkingDay) {
            return $status;
        }

        if ($record->clock_out) {
            return 'completed';
        }

        if ($record->clock_in) {
            return 'working';
        }

        return 'not_clocked';
    }

    private function japanesePublicHolidays(int $year): array
    {
        if (isset($this->publicHolidayCache[$year])) {
            return $this->publicHolidayCache[$year];
        }

        $holidays = [
            "{$year}-01-01",
            Carbon::parse("second monday of January {$year}")->toDateString(),
            "{$year}-02-11",
            "{$year}-02-23",
            sprintf('%d-03-%02d', $year, $this->vernalEquinoxDay($year)),
            "{$year}-04-29",
            "{$year}-05-03",
            "{$year}-05-04",
            "{$year}-05-05",
            Carbon::parse("third monday of July {$year}")->toDateString(),
            "{$year}-08-11",
            Carbon::parse("third monday of September {$year}")->toDateString(),
            sprintf('%d-09-%02d', $year, $this->autumnalEquinoxDay($year)),
            Carbon::parse("second monday of October {$year}")->toDateString(),
            "{$year}-11-03",
            "{$year}-11-23",
        ];

        $holidays = collect($holidays)->unique()->sort()->values();
        $holidaySet = array_fill_keys($holidays->all(), true);

        foreach ($holidays as $holiday) {
            if (! Carbon::parse($holiday)->isSunday()) {
                continue;
            }

            $substitute = Carbon::parse($holiday)->addDay();
            while (isset($holidaySet[$substitute->toDateString()])) {
                $substitute->addDay();
            }
            $holidaySet[$substitute->toDateString()] = true;
        }

        $holidayDates = collect(array_keys($holidaySet))->sort()->values();
        for ($date = Carbon::create($year, 1, 2); $date->year === $year; $date->addDay()) {
            if ($date->isWeekend() || isset($holidaySet[$date->toDateString()])) {
                continue;
            }

            if (isset($holidaySet[$date->copy()->subDay()->toDateString()])
                && isset($holidaySet[$date->copy()->addDay()->toDateString()])) {
                $holidaySet[$date->toDateString()] = true;
            }
        }

        return $this->publicHolidayCache[$year] = collect(array_keys($holidaySet))->sort()->values()->all();
    }

    private function vernalEquinoxDay(int $year): int
    {
        return (int) floor(20.8431 + (0.242194 * ($year - 1980)) - floor(($year - 1980) / 4));
    }

    private function autumnalEquinoxDay(int $year): int
    {
        return (int) floor(23.2488 + (0.242194 * ($year - 1980)) - floor(($year - 1980) / 4));
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'hire_date' => $user->hire_date?->format('Y-m-d') ?? '',
            'retirement_date' => $user->retirement_date?->format('Y-m-d') ?? '',
            'retired_at' => $user->retired_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
            'is_retirement_scheduled' => $user->isRetirementScheduled(),
            'management_number' => $user->management_number ?? '',
            'hourly_wage' => $user->hourly_wage,
            'health_insurance_deduction' => $user->health_insurance_deduction ?? 0,
            'nursing_care_insurance_deduction' => $user->nursing_care_insurance_deduction ?? 0,
            'welfare_pension_deduction' => $user->welfare_pension_deduction ?? 0,
            'employment_insurance_rate' => $user->employment_insurance_rate ?? 0,
            'income_tax_deduction' => $user->income_tax_deduction ?? 0,
            'resident_tax_deduction' => $user->resident_tax_deduction ?? 0,
            'department' => $user->department ?? '',
            'display_order' => $user->display_order ?? 0,
            'department_display_order' => $user->department_display_order ?? 0,
            'business_category' => $user->business_category ?? '',
            'work_style' => $user->work_style ?? '',
            'commute_limit_days' => $user->commute_limit_days,
            'paid_leave_remaining_days' => $user->paid_leave_remaining_days ?? $user->calculatedPaidLeaveRemainingDays(),
            'height_cm' => $user->height_cm,
            'weight_kg' => $user->weight_kg,
            'gender' => $user->gender ?? '',
            'default_clock_in' => $user->default_clock_in ? substr($user->default_clock_in, 0, 5) : '09:00',
            'default_clock_out' => $user->default_clock_out ? substr($user->default_clock_out, 0, 5) : '18:00',
            'default_break_minutes' => $user->default_break_minutes ?? 60,
            'workday_settings' => $user->normalizedWorkdaySettings(),
        ];
    }

    private function summary($records): array
    {
        $workedMinutes = $records->sum('worked_minutes');

        return [
            'days' => $records->whereIn('status', ['working', 'completed'])->count(),
            'completed' => $records->where('status', 'completed')->count(),
            'holidays' => $records->where('status', 'holiday')->count(),
            'absences' => $records->where('status', 'absence')->count(),
            'worked_minutes' => $workedMinutes,
            'overtime_minutes' => max(0, $workedMinutes - ($records->where('status', 'completed')->count() * 8 * 60)),
        ];
    }

    private function blankDashboardSummary(): array
    {
        return [
            'target_days' => 0,
            'scheduled_days' => 0,
            'attendance_days' => 0,
            'completed_days' => 0,
            'working_days' => 0,
            'not_clocked_days' => 0,
            'holiday_days' => 0,
            'absence_days' => 0,
            'paid_leave_days' => 0.0,
            'late_days' => 0,
            'early_leave_days' => 0,
            'business_support_days' => 0,
            'worked_minutes' => 0,
            'break_minutes' => 0,
            'attendance_rate' => 0.0,
            'average_worked_minutes' => 0,
        ];
    }

    private function dashboardRow(User $user, string $date, ?AttendanceRecord $record, $requests): array
    {
        $requests = $requests ?? collect();
        $isNonWorkingDay = $this->isNonWorkingDate($date, $user);
        $status = $record
            ? $this->normalizedDisplayRecordStatus($record, $isNonWorkingDay)
            : ($isNonWorkingDay ? 'holiday' : 'not_clocked');
        $displayStatus = $this->displayStatus($record, $requests, $status);
        $calendarStatus = $this->calendarDisplayStatus($date, $user, $record);
        if ($calendarStatus) {
            $displayStatus = $calendarStatus;
        }

        $clockIn = $record?->clock_in ? Carbon::parse($record->clock_in) : null;
        $clockOut = $record?->clock_out ? Carbon::parse($record->clock_out) : null;
        $breakMinutes = (int) ($record?->break_minutes ?? 0);
        $workedMinutes = $clockIn && $clockOut
            ? max(0, $clockIn->diffInMinutes($clockOut) - $breakMinutes)
            : 0;

        return [
            'status' => $status,
            'display_status_type' => $displayStatus['type'] ?? $status,
            'worked_minutes' => $workedMinutes,
            'break_minutes' => $breakMinutes,
            'is_non_working_day' => $isNonWorkingDay,
        ];
    }

    private function addDashboardRow(array &$summary, array $row): void
    {
        $status = $row['display_status_type'] ?: $row['status'];
        $isHoliday = in_array($status, ['holiday', 'free_attendance'], true) || (bool) ($row['is_non_working_day'] ?? false);
        $summary['target_days']++;
        $summary['worked_minutes'] += (int) ($row['worked_minutes'] ?? 0);
        $summary['break_minutes'] += (int) ($row['break_minutes'] ?? 0);

        if ($isHoliday) {
            $summary['holiday_days']++;
        } else {
            $summary['scheduled_days']++;
        }

        if (in_array($row['status'], ['completed', 'working'], true) || (int) ($row['worked_minutes'] ?? 0) > 0) {
            $summary['attendance_days']++;
        }

        if ($row['status'] === 'completed') {
            $summary['completed_days']++;
        }

        if ($row['status'] === 'working') {
            $summary['working_days']++;
        }

        if ($status === 'not_clocked' && ! $isHoliday) {
            $summary['not_clocked_days']++;
        }

        if ($status === 'absence') {
            $summary['absence_days']++;
        }

        if (in_array($status, ['paid_leave', 'planned_vacation'], true)) {
            $summary['paid_leave_days'] += 1;
        }

        if (in_array($status, ['morning_paid_leave', 'afternoon_paid_leave'], true)) {
            $summary['paid_leave_days'] += 0.5;
        }

        if (in_array($status, ['late', 'late_and_early_leave'], true)) {
            $summary['late_days']++;
        }

        if (in_array($status, ['early_leave', 'early_leave_planned', 'early_leave_done', 'late_and_early_leave'], true)) {
            $summary['early_leave_days']++;
        }

        if ($status === 'business_support') {
            $summary['business_support_days']++;
        }
    }

    private function finalizeDashboardSummary(array &$summary): void
    {
        $summary['attendance_rate'] = $summary['scheduled_days'] > 0
            ? round(($summary['attendance_days'] / $summary['scheduled_days']) * 100, 1)
            : 0.0;
        $summary['average_worked_minutes'] = $summary['attendance_days'] > 0
            ? (int) round($summary['worked_minutes'] / $summary['attendance_days'])
            : 0;
    }

    private function payrollDay(User $user, string $date, ?AttendanceRecord $record, $requests): array
    {
        $requests = $requests ?? collect();
        $status = $this->pdfStatusKey($user, $date, $record, $requests, $this->isNonWorkingDate($date, $user));
        $scheduledMinutes = $this->scheduledPayrollMinutes($user, $date);
        $workMinutes = $this->recordPayrollMinutes($record);
        $paidLeaveMinutes = 0;

        if (in_array($status, ['paid_leave', 'planned_vacation'], true)) {
            $paidLeaveMinutes = $scheduledMinutes;
            $workMinutes = 0;
        }

        if (in_array($status, ['morning_paid_leave', 'afternoon_paid_leave'], true)) {
            $paidLeaveMinutes = (int) round($scheduledMinutes / 2);
        }

        if ($requests->contains('type', 'business_support') && $workMinutes === 0) {
            $workMinutes = 60;
        }

        return [
            'work_minutes' => $workMinutes,
            'paid_leave_minutes' => $paidLeaveMinutes,
            'payable_minutes' => $workMinutes + $paidLeaveMinutes,
            'attendance_day' => $workMinutes > 0 ? 1 : 0,
            'paid_leave_day' => in_array($status, ['paid_leave', 'planned_vacation'], true) ? 1 : (in_array($status, ['morning_paid_leave', 'afternoon_paid_leave'], true) ? 0.5 : 0),
            'absence_day' => $status === 'absence' ? 1 : 0,
            'business_support_day' => $requests->contains('type', 'business_support') || $record?->status === 'business_support' ? 1 : 0,
        ];
    }

    private function recordPayrollMinutes(?AttendanceRecord $record): int
    {
        if (! $record) {
            return 0;
        }

        $clockIn = $record->declared_clock_in ? substr($record->declared_clock_in, 0, 5) : ($record->clock_in ? substr($record->clock_in, 0, 5) : '');
        $clockOut = $record->declared_clock_out ? substr($record->declared_clock_out, 0, 5) : ($record->clock_out ? substr($record->clock_out, 0, 5) : '');
        $rangeMinutes = $this->timeRangeMinutes($clockIn, $clockOut);

        if ($rangeMinutes === null) {
            return 0;
        }

        $breakMinutes = (int) ($record->declared_break_minutes ?? $record->break_minutes ?? 0);

        return max(0, $rangeMinutes - $breakMinutes);
    }

    private function scheduledPayrollMinutes(User $user, string $date): int
    {
        if (! $this->isScheduledWorkingDate($date, $user)) {
            return 0;
        }

        $weekdaySetting = $user->normalizedWorkdaySettings()[(string) Carbon::parse($date)->dayOfWeekIso] ?? null;
        $clockIn = $weekdaySetting['default_clock_in'] ?? ($user->default_clock_in ? substr($user->default_clock_in, 0, 5) : '09:00');
        $clockOut = $weekdaySetting['default_clock_out'] ?? ($user->default_clock_out ? substr($user->default_clock_out, 0, 5) : '18:00');
        $breakMinutes = (int) ($weekdaySetting['default_break_minutes'] ?? $user->default_break_minutes ?? 60);
        $rangeMinutes = $this->timeRangeMinutes($clockIn, $clockOut);

        return $rangeMinutes === null ? 0 : max(0, $rangeMinutes - $breakMinutes);
    }

    private function monthlyCalendarHighlights(Carbon $start, Carbon $end): array
    {
        $entries = CalendarEntry::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('type', ['saturday_work', 'holiday_off'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (CalendarEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->date->format('Y-m-d'),
                'type' => $entry->type,
                'description' => $entry->description ?? '',
            ]);

        return [
            'saturday_work' => $entries->where('type', 'saturday_work')->values(),
            'holiday_off' => $entries->where('type', 'holiday_off')->values(),
        ];
    }

    private function activeUsersQuery()
    {
        return User::query()
            ->where('role', 'user')
            ->where(function ($query) {
                $query
                    ->whereNull('retirement_date')
                    ->orWhereDate('retirement_date', '>=', today(config('app.timezone')));
            })
            ->orderBy('department_display_order')
            ->orderBy('department')
            ->orderBy('display_order')
            ->orderBy('id');
    }

    private function syncDuePlannedVacationAdjustments($users): void
    {
        $today = Carbon::today(config('app.timezone'));

        if ($users->isEmpty()) {
            return;
        }

        $userIds = $users->pluck('id');
        $plannedVacationEntries = CalendarEntry::query()
            ->where('type', 'planned_vacation')
            ->whereDate('date', '<=', $today)
            ->get();

        $plannedRecordsByUserAndDate = AttendanceRecord::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', '<=', $today)
            ->where('status', 'planned_vacation')
            ->get()
            ->keyBy(fn (AttendanceRecord $record) => $record->user_id.'|'.$record->work_date->format('Y-m-d'));

        $adjuster = app(PaidLeaveAdjuster::class);

        DB::transaction(function () use ($users, $plannedVacationEntries, $plannedRecordsByUserAndDate, $adjuster): void {
            foreach ($plannedRecordsByUserAndDate as $record) {
                $adjuster->syncForAttendanceRecord($record);
            }

            foreach ($plannedVacationEntries as $entry) {
                $dateKey = $entry->date->format('Y-m-d');

                foreach ($users as $user) {
                    if ($plannedRecordsByUserAndDate->has($user->id.'|'.$dateKey)) {
                        $adjuster->removeForCalendarPlannedVacation($entry, $user);
                        continue;
                    }

                    $adjuster->syncForCalendarPlannedVacation($entry, $user);
                }
            }
        });
    }
}

