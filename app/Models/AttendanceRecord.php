<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'user_id',
        'work_date',
        'clock_in',
        'clock_in_recorded_at',
        'clock_out',
        'clock_out_recorded_at',
        'declared_clock_in',
        'declared_clock_out',
        'declared_break_minutes',
        'work_location',
        'meal_percentage',
        'missed_meal',
        'break_minutes',
        'status',
        'note',
        'admin_comment',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'clock_in_recorded_at' => 'datetime',
            'clock_out_recorded_at' => 'datetime',
            'declared_break_minutes' => 'integer',
            'meal_percentage' => 'integer',
            'missed_meal' => 'boolean',
            'break_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
