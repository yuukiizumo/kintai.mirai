<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'admin_level',
        'hire_date',
        'retirement_date',
        'retired_at',
        'management_number',
        'hourly_wage',
        'health_insurance_deduction',
        'nursing_care_insurance_deduction',
        'welfare_pension_deduction',
        'employment_insurance_rate',
        'income_tax_deduction',
        'resident_tax_deduction',
        'department',
        'display_order',
        'department_display_order',
        'business_category',
        'work_style',
        'commute_limit_days',
        'paid_leave_remaining_days',
        'height_cm',
        'weight_kg',
        'gender',
        'default_clock_in',
        'default_clock_out',
        'default_break_minutes',
        'workday_settings',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'hire_date' => 'date',
            'retirement_date' => 'date',
            'retired_at' => 'datetime',
            'hourly_wage' => 'integer',
            'health_insurance_deduction' => 'integer',
            'nursing_care_insurance_deduction' => 'integer',
            'welfare_pension_deduction' => 'integer',
            'employment_insurance_rate' => 'decimal:3',
            'income_tax_deduction' => 'integer',
            'resident_tax_deduction' => 'integer',
            'display_order' => 'integer',
            'department_display_order' => 'integer',
            'paid_leave_remaining_days' => 'decimal:1',
            'height_cm' => 'decimal:1',
            'weight_kg' => 'decimal:1',
            'default_break_minutes' => 'integer',
            'workday_settings' => 'array',
            'password' => 'hashed',
        ];
    }

    public function normalizedWorkdaySettings(): array
    {
        $settings = $this->workday_settings ?? [];

        return collect(range(1, 6))
            ->mapWithKeys(fn (int $weekday) => [
                (string) $weekday => [
                    'default_clock_in' => $settings[$weekday]['default_clock_in'] ?? $settings[(string) $weekday]['default_clock_in'] ?? substr((string) $this->default_clock_in, 0, 5) ?: '09:00',
                    'default_clock_out' => $settings[$weekday]['default_clock_out'] ?? $settings[(string) $weekday]['default_clock_out'] ?? substr((string) $this->default_clock_out, 0, 5) ?: '18:00',
                    'default_break_minutes' => (int) ($settings[$weekday]['default_break_minutes'] ?? $settings[(string) $weekday]['default_break_minutes'] ?? $this->default_break_minutes ?? 60),
                    'is_working_day' => (bool) ($settings[$weekday]['is_working_day'] ?? $settings[(string) $weekday]['is_working_day'] ?? true),
                ],
            ])
            ->all();
    }

    public function calculatedPaidLeaveRemainingDays(?CarbonInterface $asOf = null): float
    {
        if (! $this->hire_date) {
            return 0.0;
        }

        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $expirationThreshold = $asOf->copy()->subYears(2);
        $total = 0;

        for ($grantIndex = 0; ; $grantIndex++) {
            $grantDate = $this->hire_date->copy()->addMonthsNoOverflow(6 + ($grantIndex * 12))->startOfDay();

            if ($grantDate->greaterThan($asOf)) {
                break;
            }

            if ($grantDate->greaterThan($expirationThreshold)) {
                $total += $this->paidLeaveGrantDays($grantIndex);
            }
        }

        return (float) $total;
    }

    private function paidLeaveGrantDays(int $grantIndex): int
    {
        return [10, 11, 12, 14, 16, 18][$grantIndex] ?? 20;
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function attendanceRequests(): HasMany
    {
        return $this->hasMany(AttendanceRequest::class);
    }

    public function adminMessages(): HasMany
    {
        return $this->hasMany(AdminMessage::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStrongAdmin(): bool
    {
        return $this->isAdmin() && ($this->admin_level ?? 'strong') === 'strong';
    }

    public function isEffectivelyRetired(): bool
    {
        if ($this->retirement_date) {
            return $this->retirement_date->lt(Carbon::today(config('app.timezone')));
        }

        return $this->retired_at !== null;
    }

    public function isRetirementScheduled(): bool
    {
        return $this->retirement_date
            && ! $this->isEffectivelyRetired();
    }
}
