<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Support\PaidLeaveAdjuster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceRequestController extends Controller
{
    private const REASON_CATEGORIES = [
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
        $userId = $request->integer('user_id');
        $allUsers = $request->boolean('all_users');
        $page = max(1, $request->integer('page', 1));
        $perPage = 20;
        $type = $request->validate([
            'type' => ['nullable', Rule::in(AttendanceRequest::TYPES)],
        ])['type'] ?? null;

        $query = AttendanceRequest::query()
            ->with('user:id,name,email')
            ->whereBetween('request_date', ["{$month}-01", Carbon::parse("{$month}-01")->endOfMonth()->toDateString()])
            ->orderByDesc('request_date')
            ->orderByDesc('id');

        if (! $viewer->isAdmin()) {
            $query->where('user_id', $viewer->id);
        } elseif (! $allUsers && $userId) {
            $query->where('user_id', $userId);
        }

        if ($type) {
            $query->where('type', $type);
        }

        $total = (clone $query)->count();

        return response()->json([
            'requests' => $query
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get()
                ->map(fn (AttendanceRequest $request) => $this->serializeRequest($request)),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $request->user();
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'type' => ['required', Rule::in(AttendanceRequest::TYPES)],
            'request_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after_or_equal:start_time'],
            'reason_category' => ['nullable', Rule::in(self::REASON_CATEGORIES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $viewer->isAdmin()) {
            $data['user_id'] = $viewer->id;
        } else {
            $data['user_id'] = $data['user_id'] ?? $viewer->id;
        }

        $attendanceRequest = DB::transaction(function () use ($data) {
            $attendanceRequest = AttendanceRequest::create([
                ...$data,
                'status' => 'pending',
                'admin_checked' => false,
                'service_manager_checked' => false,
            ]);

            $this->applyAttendanceRequestToRecord($attendanceRequest);

            app(PaidLeaveAdjuster::class)->syncForAttendanceRequest($attendanceRequest);

            return $attendanceRequest;
        });

        return response()->json($this->serializeRequest($attendanceRequest->load('user:id,name,email')), 201);
    }

    public function updateChecks(Request $request, AttendanceRequest $attendanceRequest)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'admin_checked' => ['required', 'boolean'],
            'service_manager_checked' => ['required', 'boolean'],
        ]);

        $attendanceRequest->update([
            ...$data,
            'status' => $this->checkedStatus($data['admin_checked'], $data['service_manager_checked']),
        ]);

        return response()->json($this->serializeRequest($attendanceRequest->load('user:id,name,email')));
    }

    public function destroy(Request $request, AttendanceRequest $attendanceRequest)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->id === $attendanceRequest->user_id, 403);

        DB::transaction(function () use ($attendanceRequest) {
            app(PaidLeaveAdjuster::class)->removeForAttendanceRequest($attendanceRequest);
            $attendanceRequest->delete();
        });

        return response()->noContent();
    }

    private function serializeRequest(AttendanceRequest $request): array
    {
        $status = $this->checkedStatus((bool) $request->admin_checked, (bool) $request->service_manager_checked);

        return [
            'id' => $request->id,
            'user_id' => $request->user_id,
            'employee' => $request->user?->name,
            'type' => $request->type,
            'request_date' => $request->request_date->format('Y-m-d'),
            'start_time' => $request->start_time ? substr($request->start_time, 0, 5) : '',
            'end_time' => $request->end_time ? substr($request->end_time, 0, 5) : '',
            'reason_category' => $request->reason_category ?? '',
            'reason' => $request->reason ?? '',
            'status' => $status,
            'admin_checked' => $request->admin_checked,
            'service_manager_checked' => $request->service_manager_checked,
            'applied_attendance_record_id' => $request->applied_attendance_record_id,
            'created_at' => $request->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'submitted_at' => $request->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
        ];
    }

    private function checkedStatus(bool $adminChecked, bool $serviceManagerChecked): string
    {
        if ($serviceManagerChecked) {
            return 'service_manager_checked';
        }

        if ($adminChecked) {
            return 'admin_checked';
        }

        return 'pending';
    }

    private function applyAttendanceRequestToRecord(AttendanceRequest $attendanceRequest): void
    {
        if ($attendanceRequest->type === 'business_support') {
            $this->applyBusinessSupportRequest($attendanceRequest);

            return;
        }

        $status = $this->attendanceStatusForRequest($attendanceRequest);
        if (! $status) {
            return;
        }

        $record = AttendanceRecord::query()
            ->where('user_id', $attendanceRequest->user_id)
            ->whereDate('work_date', $attendanceRequest->request_date->format('Y-m-d'))
            ->first();

        $record ??= new AttendanceRecord([
            'user_id' => $attendanceRequest->user_id,
            'work_date' => $attendanceRequest->request_date->format('Y-m-d'),
            'break_minutes' => 0,
        ]);

        $record->fill([
            'status' => $status,
            'break_minutes' => in_array($status, ['absence', 'paid_leave', 'morning_paid_leave', 'afternoon_paid_leave'], true)
                ? 0
                : ($record->break_minutes ?? 0),
        ]);
        $record->save();
        app(PaidLeaveAdjuster::class)->syncForAttendanceRecord($record);

        $attendanceRequest->forceFill([
            'applied_attendance_record_id' => $record->id,
        ])->save();
    }

    private function attendanceStatusForRequest(AttendanceRequest $attendanceRequest): ?string
    {
        if ($attendanceRequest->type === 'late' || $attendanceRequest->type === 'early_leave') {
            $hasLate = $attendanceRequest->type === 'late'
                || AttendanceRequest::query()
                    ->where('user_id', $attendanceRequest->user_id)
                    ->whereDate('request_date', $attendanceRequest->request_date->format('Y-m-d'))
                    ->where('type', 'late')
                    ->whereKeyNot($attendanceRequest->id)
                    ->exists();
            $hasEarlyLeave = $attendanceRequest->type === 'early_leave'
                || AttendanceRequest::query()
                    ->where('user_id', $attendanceRequest->user_id)
                    ->whereDate('request_date', $attendanceRequest->request_date->format('Y-m-d'))
                    ->where('type', 'early_leave')
                    ->whereKeyNot($attendanceRequest->id)
                    ->exists();

            if ($hasLate && $hasEarlyLeave) {
                return 'late_and_early_leave';
            }

            return $attendanceRequest->type;
        }

        return match ($attendanceRequest->type) {
            'absence' => 'absence',
            'paid_leave' => 'paid_leave',
            'morning_paid_leave' => 'morning_paid_leave',
            'afternoon_paid_leave' => 'afternoon_paid_leave',
            default => null,
        };
    }

    private function applyBusinessSupportRequest(AttendanceRequest $attendanceRequest): void
    {
        $record = AttendanceRecord::query()
            ->where('user_id', $attendanceRequest->user_id)
            ->whereDate('work_date', $attendanceRequest->request_date->format('Y-m-d'))
            ->first();

        $snapshot = $record ? $this->attendanceRecordSnapshot($record) : null;

        $record ??= new AttendanceRecord([
            'user_id' => $attendanceRequest->user_id,
            'work_date' => $attendanceRequest->request_date->format('Y-m-d'),
        ]);

        $record->fill([
            'clock_in' => '10:00',
            'clock_out' => '11:00',
            'declared_clock_in' => '10:00',
            'declared_clock_out' => '11:00',
            'break_minutes' => 0,
            'declared_break_minutes' => 0,
            'work_location' => $record->work_location ?? 'office',
            'status' => 'business_support',
        ]);
        $record->save();
        app(PaidLeaveAdjuster::class)->syncForAttendanceRecord($record);

        $attendanceRequest->forceFill([
            'start_time' => '10:00',
            'end_time' => '11:00',
            'applied_attendance_record_id' => $record->id,
            'attendance_record_snapshot' => $snapshot,
        ])->save();
    }

    private function restoreBusinessSupportRequest(AttendanceRequest $attendanceRequest): void
    {
        if (! $attendanceRequest->applied_attendance_record_id) {
            return;
        }

        $record = AttendanceRecord::query()->find($attendanceRequest->applied_attendance_record_id);
        $snapshot = $attendanceRequest->attendance_record_snapshot;

        if (! $snapshot) {
            $record?->delete();

            return;
        }

        if (! $record) {
            $record = new AttendanceRecord;
        }

        $record->forceFill($snapshot);
        $record->save();
        app(PaidLeaveAdjuster::class)->syncForAttendanceRecord($record);
    }

    private function attendanceRecordSnapshot(AttendanceRecord $record): array
    {
        return [
            'id' => $record->id,
            'user_id' => $record->user_id,
            'work_date' => $record->work_date->format('Y-m-d'),
            'clock_in' => $record->clock_in ? substr($record->clock_in, 0, 5) : null,
            'clock_out' => $record->clock_out ? substr($record->clock_out, 0, 5) : null,
            'declared_clock_in' => $record->declared_clock_in ? substr($record->declared_clock_in, 0, 5) : null,
            'declared_clock_out' => $record->declared_clock_out ? substr($record->declared_clock_out, 0, 5) : null,
            'declared_break_minutes' => $record->declared_break_minutes,
            'work_location' => $record->work_location,
            'break_minutes' => $record->break_minutes,
            'status' => $record->status,
            'note' => $record->note,
            'created_at' => $record->created_at?->toDateTimeString(),
            'updated_at' => $record->updated_at?->toDateTimeString(),
        ];
    }
}
