<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarEntry extends Model
{
    public const TYPES = [
        'planned_vacation',
        'holiday_off',
        'saturday_work',
        'self_report_due',
        'free_attendance_8',
        'free_attendance_4',
    ];

    protected $fillable = [
        'id',
        'date',
        'processed',
        'type',
        'description',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'processed' => 'boolean',
        ];
    }

    public static function labelFor(?string $type): string
    {
        return match ($type) {
            'planned_vacation' => '計画有給',
            'holiday_off' => '休日',
            'saturday_work' => '土曜出勤日',
            'self_report_due' => '自己管理レポート提出日',
            'free_attendance_8' => '自由出勤日（-8日）',
            'free_attendance_4' => '自由出勤日（-4日）',
            default => $type ?? '',
        };
    }
}
