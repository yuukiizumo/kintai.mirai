<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelfManagementReport extends Model
{
    protected $fillable = [
        'user_id',
        'report_date',
        'work_rating',
        'life_rating',
        'monthly_reflection',
        'next_month_goal',
        'skill_progress',
        'activity_status',
        'activity_detail',
        'other',
        'admin_comment',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
