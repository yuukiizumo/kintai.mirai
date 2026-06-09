<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\PaidLeaveAdjuster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CalendarEntryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $month = $request->string('month', now()->format('Y-m'))->toString();
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = CalendarEntry::query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (CalendarEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->date->format('Y-m-d'),
                'type' => $entry->type,
                'type_label' => CalendarEntry::labelFor($entry->type),
                'description' => $entry->description ?? '',
                'processed' => $entry->processed,
            ]);

        $counts = CalendarEntry::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->mapWithKeys(fn ($total, $type) => [
                $type => [
                    'label' => CalendarEntry::labelFor($type),
                    'total' => $total,
                ],
            ]);

        return response()->json([
            'month' => $month,
            'entries' => $entries,
            'counts' => $counts,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'type' => ['required', Rule::in(CalendarEntry::TYPES)],
            'description' => ['nullable', 'string', 'max:255'],
            'processed' => ['nullable', 'boolean'],
        ]);

        $entry = CalendarEntry::query()->updateOrCreate(
            [
                'date' => $data['date'],
                'type' => $data['type'],
            ],
            [
                'description' => $data['description'] ?? null,
                'processed' => (bool) ($data['processed'] ?? false),
            ],
        );

        if ($entry->type === 'planned_vacation' && $entry->date->lte(Carbon::today(config('app.timezone')))) {
            $this->syncCalendarPlannedVacation($entry);
        }

        return response()->json([
            'id' => $entry->id,
            'date' => $entry->date->format('Y-m-d'),
            'type' => $entry->type,
            'type_label' => CalendarEntry::labelFor($entry->type),
            'description' => $entry->description ?? '',
            'processed' => $entry->processed,
        ], 201);
    }

    public function destroy(Request $request, CalendarEntry $calendarEntry)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $calendarEntry->delete();

        return response()->noContent();
    }

    private function syncCalendarPlannedVacation(CalendarEntry $entry): void
    {
        $users = User::query()
            ->where('role', 'user')
            ->where(function ($query) {
                $query
                    ->whereNull('retirement_date')
                    ->orWhereDate('retirement_date', '>=', today(config('app.timezone')));
            })
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $plannedRecordUserIds = AttendanceRecord::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('work_date', $entry->date)
            ->where('status', 'planned_vacation')
            ->pluck('user_id')
            ->all();

        $adjuster = app(PaidLeaveAdjuster::class);

        DB::transaction(function () use ($users, $plannedRecordUserIds, $entry, $adjuster): void {
            foreach ($users as $user) {
                if (in_array($user->id, $plannedRecordUserIds, true)) {
                    $adjuster->removeForCalendarPlannedVacation($entry, $user);
                    continue;
                }

                $adjuster->syncForCalendarPlannedVacation($entry, $user);
            }
        });
    }
}
