<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AttendanceRequest extends Model
{
    public const TYPES = [
        'absence',
        'late',
        'early_leave',
        'paid_leave',
        'morning_paid_leave',
        'afternoon_paid_leave',
        'overtime',
        'business_support',
        'change',
        'care_service',
        'off_hours_medical',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'request_date',
        'start_time',
        'end_time',
        'reason',
        'reason_category',
        'status',
        'admin_checked',
        'service_manager_checked',
        'applied_attendance_record_id',
        'attendance_record_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'admin_checked' => 'boolean',
            'service_manager_checked' => 'boolean',
            'attendance_record_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
