<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserWorkSettingController extends Controller
{
    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->isStrongAdmin(), 403);
        abort_unless($user->role === 'user', 404);

        $data = $request->validate([
            'workday_settings' => ['required', 'array'],
            'workday_settings.1.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.1.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.1.default_clock_in'],
            'workday_settings.1.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.1.is_working_day' => ['nullable', 'boolean'],
            'workday_settings.2.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.2.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.2.default_clock_in'],
            'workday_settings.2.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.2.is_working_day' => ['nullable', 'boolean'],
            'workday_settings.3.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.3.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.3.default_clock_in'],
            'workday_settings.3.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.3.is_working_day' => ['nullable', 'boolean'],
            'workday_settings.4.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.4.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.4.default_clock_in'],
            'workday_settings.4.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.4.is_working_day' => ['nullable', 'boolean'],
            'workday_settings.5.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.5.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.5.default_clock_in'],
            'workday_settings.5.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.5.is_working_day' => ['nullable', 'boolean'],
            'workday_settings.6.default_clock_in' => ['required', 'date_format:H:i'],
            'workday_settings.6.default_clock_out' => ['required', 'date_format:H:i', 'after:workday_settings.6.default_clock_in'],
            'workday_settings.6.default_break_minutes' => ['required', 'integer', 'min:0', 'max:600'],
            'workday_settings.6.is_working_day' => ['nullable', 'boolean'],
        ]);

        $mondaySettings = $data['workday_settings'][1] ?? $data['workday_settings']['1'];

        $user->update([
            'default_clock_in' => $mondaySettings['default_clock_in'],
            'default_clock_out' => $mondaySettings['default_clock_out'],
            'default_break_minutes' => $mondaySettings['default_break_minutes'],
            'workday_settings' => $data['workday_settings'],
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'default_clock_in' => substr($user->default_clock_in, 0, 5),
            'default_clock_out' => substr($user->default_clock_out, 0, 5),
            'default_break_minutes' => $user->default_break_minutes,
            'workday_settings' => $user->normalizedWorkdaySettings(),
        ]);
    }
}
