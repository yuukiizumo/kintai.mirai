<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RetiredUserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $retiredUsers = User::query()
            ->where('role', 'user')
            ->where(function ($query) {
                $query
                    ->whereDate('retirement_date', '<', Carbon::today(config('app.timezone')))
                    ->orWhere(function ($query) {
                        $query->whereNotNull('retired_at')->whereNull('retirement_date');
                    });
            })
            ->orderByDesc('retirement_date')
            ->orderBy('id')
            ->get();

        $selectedUser = $request->integer('user_id')
            ? $retiredUsers->firstWhere('id', $request->integer('user_id'))
            : $retiredUsers->first();

        return response()->json([
            'users' => $retiredUsers->map(fn (User $user) => $this->serializeUser($user)),
            'selected_user_id' => $selectedUser?->id,
            'records' => $selectedUser ? $this->attendanceRecords($selectedUser) : [],
            'requests' => $selectedUser ? $this->attendanceRequests($selectedUser) : [],
        ]);
    }

    public function retire(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($user->role === 'user', 404);

        $data = $request->validate([
            'retirement_date' => ['required', 'date'],
        ]);

        $retirementDate = Carbon::parse($data['retirement_date'], config('app.timezone'))->toDateString();

        $user->update([
            'retirement_date' => $data['retirement_date'],
            'retired_at' => $retirementDate < Carbon::today(config('app.timezone'))->toDateString() ? now() : null,
        ]);

        return response()->json($this->serializeUser($user->refresh()));
    }

    public function restore(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($user->role === 'user', 404);

        $user->update([
            'retirement_date' => null,
            'retired_at' => null,
        ]);

        return response()->json($this->serializeUser($user->refresh()));
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($user->role === 'user', 404);
        abort_unless($user->isEffectivelyRetired(), 422);

        $user->delete();

        return response()->noContent();
    }

    private function attendanceRecords(User $user): array
    {
        return AttendanceRecord::query()
            ->where('user_id', $user->id)
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AttendanceRecord $record) => [
                'id' => $record->id,
                'work_date' => $record->work_date->format('Y-m-d'),
                'clock_in' => $record->clock_in ? substr($record->clock_in, 0, 5) : '',
                'clock_out' => $record->clock_out ? substr($record->clock_out, 0, 5) : '',
                'break_minutes' => $record->break_minutes,
                'worked_minutes' => $this->workedMinutes($record),
                'work_location' => $record->work_location ?? '',
                'work_location_label' => $this->workLocationLabel($record->work_location),
                'status' => $record->status,
                'note' => $record->note ?? '',
            ])
            ->all();
    }

    private function attendanceRequests(User $user): array
    {
        return AttendanceRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('request_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AttendanceRequest $request) => [
                'id' => $request->id,
                'submitted_at' => $request->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
                'request_date' => $request->request_date->format('Y-m-d'),
                'type' => $request->type,
                'start_time' => $request->start_time ? substr($request->start_time, 0, 5) : '',
                'end_time' => $request->end_time ? substr($request->end_time, 0, 5) : '',
                'reason_category' => $request->reason_category ?? '',
                'reason' => $request->reason ?? '',
                'status' => $request->status,
            ])
            ->all();
    }

    private function workedMinutes(AttendanceRecord $record): int
    {
        if (! $record->clock_in || ! $record->clock_out) {
            return 0;
        }

        return max(0, intdiv(abs(strtotime($record->clock_out) - strtotime($record->clock_in)), 60) - $record->break_minutes);
    }

    private function workLocationLabel(?string $workLocation): string
    {
        return match ($workLocation) {
            'office' => '通所',
            'home' => '在宅',
            default => '',
        };
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'management_number' => $user->management_number ?? '',
            'retirement_date' => $user->retirement_date?->format('Y-m-d') ?? '',
            'retired_at' => $user->retired_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
            'is_retirement_scheduled' => $user->isRetirementScheduled(),
        ];
    }
}
