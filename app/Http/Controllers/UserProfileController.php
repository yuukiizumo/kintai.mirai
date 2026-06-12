<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->isStrongAdmin(), 403);
        abort_unless($user->role === 'user', 404);

        $this->prepareProfileInput($request, $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'retirement_date' => ['nullable', 'date'],
            'management_number' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'workday_settings' => ['required', 'array'],
            'workday_settings.1.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.1.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.1.default_clock_in'],
            'workday_settings.1.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.1.is_working_day' => ['present', 'boolean'],
            'workday_settings.2.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.2.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.2.default_clock_in'],
            'workday_settings.2.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.2.is_working_day' => ['present', 'boolean'],
            'workday_settings.3.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.3.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.3.default_clock_in'],
            'workday_settings.3.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.3.is_working_day' => ['present', 'boolean'],
            'workday_settings.4.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.4.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.4.default_clock_in'],
            'workday_settings.4.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.4.is_working_day' => ['present', 'boolean'],
            'workday_settings.5.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.5.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.5.default_clock_in'],
            'workday_settings.5.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.5.is_working_day' => ['present', 'boolean'],
            'workday_settings.6.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.6.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.6.default_clock_in'],
            'workday_settings.6.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.6.is_working_day' => ['present', 'boolean'],
            'hourly_wage' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'department' => ['nullable', 'string', 'max:255'],
            'business_category' => ['nullable', 'string', 'max:255'],
            'work_style' => ['nullable', Rule::in(['A型', 'B型'])],
            'commute_limit_days' => ['nullable', Rule::in(['-8日', '-4日'])],
            'paid_leave_remaining_days' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'gender' => ['nullable', Rule::in(['男', '女'])],
            'health_insurance_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'nursing_care_insurance_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'welfare_pension_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'employment_insurance_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'income_tax_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'resident_tax_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ]);

        $mondaySettings = $data['workday_settings'][1] ?? $data['workday_settings']['1'];
        $userForPaidLeaveCalculation = $user->replicate();
        $userForPaidLeaveCalculation->hire_date = filled($data['hire_date'] ?? null) ? Carbon::parse($data['hire_date']) : null;

        $user->update([
            ...$data,
            'paid_leave_remaining_days' => $data['paid_leave_remaining_days'] ?? $userForPaidLeaveCalculation->calculatedPaidLeaveRemainingDays(),
            'default_clock_in' => $mondaySettings['default_clock_in'],
            'default_clock_out' => $mondaySettings['default_clock_out'],
            'default_break_minutes' => $mondaySettings['default_break_minutes'],
        ]);

        return response()->json($this->serializeUser($user->refresh()));
    }

    public function updatePayrollSettings(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($user->role === 'user', 404);

        $data = $request->validate([
            'hourly_wage' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'health_insurance_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'nursing_care_insurance_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'welfare_pension_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'employment_insurance_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'income_tax_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'resident_tax_deduction' => ['nullable', 'integer', 'min:0', 'max:9999999'],
        ]);

        $user->update([
            'hourly_wage' => $data['hourly_wage'] ?? null,
            'health_insurance_deduction' => $data['health_insurance_deduction'] ?? 0,
            'nursing_care_insurance_deduction' => $data['nursing_care_insurance_deduction'] ?? 0,
            'welfare_pension_deduction' => $data['welfare_pension_deduction'] ?? 0,
            'employment_insurance_rate' => $data['employment_insurance_rate'] ?? 0,
            'income_tax_deduction' => $data['income_tax_deduction'] ?? 0,
            'resident_tax_deduction' => $data['resident_tax_deduction'] ?? 0,
        ]);

        return response()->json($this->serializeUser($user->refresh()));
    }

    private function prepareProfileInput(Request $request, User $user): void
    {
        $currentSettings = $user->normalizedWorkdaySettings();
        $inputSettings = $request->input('workday_settings', []);
        $workdaySettings = collect(range(1, 6))
            ->mapWithKeys(function (int $weekday) use ($currentSettings, $inputSettings) {
                $key = (string) $weekday;
                $input = $inputSettings[$key] ?? $inputSettings[$weekday] ?? [];
                $current = $currentSettings[$key];

                return [
                    $key => [
                        'default_clock_in' => blank($input['default_clock_in'] ?? null) ? $current['default_clock_in'] : $input['default_clock_in'],
                        'default_clock_out' => blank($input['default_clock_out'] ?? null) ? $current['default_clock_out'] : $input['default_clock_out'],
                        'default_break_minutes' => blank($input['default_break_minutes'] ?? null) ? $current['default_break_minutes'] : $input['default_break_minutes'],
                        'is_working_day' => filter_var($input['is_working_day'] ?? $current['is_working_day'], FILTER_VALIDATE_BOOLEAN),
                    ],
                ];
            })
            ->all();

        $request->merge([
            'name' => blank($request->input('name')) ? $user->name : $request->input('name'),
            'email' => blank($request->input('email')) ? $user->email : $request->input('email'),
            'workday_settings' => $workdaySettings,
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'default_clock_in' => $user->default_clock_in ? substr($user->default_clock_in, 0, 5) : '09:00',
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
            'default_clock_out' => $user->default_clock_out ? substr($user->default_clock_out, 0, 5) : '18:00',
            'default_break_minutes' => $user->default_break_minutes ?? 60,
            'workday_settings' => $user->normalizedWorkdaySettings(),
        ];
    }
}
