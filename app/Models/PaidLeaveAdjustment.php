<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaidLeaveAdjustment extends Model
{
    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'days',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
