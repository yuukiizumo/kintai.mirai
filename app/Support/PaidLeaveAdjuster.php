<?php

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\CalendarEntry;
use App\Models\PaidLeaveAdjustment;
use App\Models\User;
use Carbon\Carbon;

class PaidLeaveAdjuster
{
    public const REQUEST_SOURCE = 'attendance_request';

    public const RECORD_SOURCE = 'attendance_record';

    public const CALENDAR_PLANNED_VACATION_SOURCE = 'calendar_planned_vacation';

    public function syncForAttendanceRequest(AttendanceRequest $attendanceRequest): void
    {
        $this->sync(
            self::REQUEST_SOURCE,
            $attendanceRequest->id,
            $attendanceRequest->user_id,
            $this->daysForType($attendanceRequest->type),
        );
    }

    public function removeForAttendanceRequest(AttendanceRequest $attendanceRequest): void
    {
        $this->sync(self::REQUEST_SOURCE, $attendanceRequest->id, $attendanceRequest->user_id, 0.0);
    }

    public function syncForAttendanceRecord(AttendanceRecord $attendanceRecord): void
    {
        $this->sync(
            self::RECORD_SOURCE,
            $attendanceRecord->id,
            $attendanceRecord->user_id,
            $this->daysForAttendanceRecord($attendanceRecord),
        );
    }

    public function removeForAttendanceRecord(AttendanceRecord $attendanceRecord): void
    {
        $this->sync(self::RECORD_SOURCE, $attendanceRecord->id, $attendanceRecord->user_id, 0.0);
    }

    public function syncForCalendarPlannedVacation(CalendarEntry $calendarEntry, User $user): void
    {
        $this->syncForUserSource(
            self::CALENDAR_PLANNED_VACATION_SOURCE,
            $calendarEntry->id,
            $user->id,
            0.0,
        );
    }

    public function removeForCalendarPlannedVacation(CalendarEntry $calendarEntry, User $user): void
    {
        $this->syncForUserSource(
            self::CALENDAR_PLANNED_VACATION_SOURCE,
            $calendarEntry->id,
            $user->id,
            0.0,
        );
    }

    public function syncCalendarPlannedVacationsForRecordDate(AttendanceRecord $attendanceRecord): void
    {
        $user = $attendanceRecord->user ?? User::query()->findOrFail($attendanceRecord->user_id);
        $this->syncCalendarPlannedVacationsForUserDate($user, $attendanceRecord->work_date, $attendanceRecord->status === 'planned_vacation');
    }

    public function syncCalendarPlannedVacationsForUserDate(User $user, Carbon|string $date, bool $skipBecauseRecordPlanned = false): void
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);
        $entries = CalendarEntry::query()
            ->where('type', 'planned_vacation')
            ->whereDate('date', $date)
            ->get();

        foreach ($entries as $entry) {
            if ($skipBecauseRecordPlanned) {
                $this->removeForCalendarPlannedVacation($entry, $user);
            } else {
                $this->syncForCalendarPlannedVacation($entry, $user);
            }
        }
    }

    private function sync(string $sourceType, int $sourceId, int $userId, float $newDays): void
    {
        $adjustment = PaidLeaveAdjustment::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();

        $oldDays = (float) ($adjustment?->days ?? 0);
        $oldUserId = $adjustment?->user_id;

        if ($adjustment && ($oldUserId !== $userId || $newDays <= 0)) {
            $this->changeRemainingDays($oldUserId, $oldDays);
            $adjustment->delete();
            $adjustment = null;
            $oldDays = 0;
        }

        if ($newDays <= 0) {
            return;
        }

        $this->changeRemainingDays($userId, -($newDays - $oldDays));

        PaidLeaveAdjustment::query()->updateOrCreate(
            ['source_type' => $sourceType, 'source_id' => $sourceId],
            ['user_id' => $userId, 'days' => $newDays],
        );
    }

    private function syncForUserSource(string $sourceType, int $sourceId, int $userId, float $newDays): void
    {
        $adjustment = PaidLeaveAdjustment::query()
            ->where('user_id', $userId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();

        $oldDays = (float) ($adjustment?->days ?? 0);

        if ($adjustment && $newDays <= 0) {
            $this->changeRemainingDays($userId, $oldDays);
            $adjustment->delete();

            return;
        }

        if ($newDays <= 0) {
            return;
        }

        $this->changeRemainingDays($userId, -($newDays - $oldDays));

        PaidLeaveAdjustment::query()->updateOrCreate(
            ['user_id' => $userId, 'source_type' => $sourceType, 'source_id' => $sourceId],
            ['days' => $newDays],
        );
    }

    private function changeRemainingDays(int $userId, float $delta): void
    {
        if ($delta == 0.0) {
            return;
        }

        $user = User::query()->lockForUpdate()->findOrFail($userId);
        $current = $user->paid_leave_remaining_days ?? $user->calculatedPaidLeaveRemainingDays();

        $user->forceFill([
            'paid_leave_remaining_days' => round(((float) $current) + $delta, 1),
        ])->save();
    }

    private function daysForType(?string $type): float
    {
        return match ($type) {
            'paid_leave' => 1.0,
            'morning_paid_leave', 'afternoon_paid_leave' => 0.5,
            default => 0.0,
        };
    }

    private function daysForAttendanceRecord(AttendanceRecord $attendanceRecord): float
    {
        return 0.0;
    }
}
