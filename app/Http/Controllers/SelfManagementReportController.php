<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\SelfManagementReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SelfManagementReportController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();
        $activeDueDates = $this->activeDueDates();
        $selectedReportDate = $this->selectedReportDate($request, $activeDueDates, $viewer->isAdmin());

        if (! $viewer->isAdmin()) {
            $report = $selectedReportDate
                ? SelfManagementReport::query()
                    ->where('user_id', $viewer->id)
                    ->whereDate('report_date', $selectedReportDate)
                    ->first()
                : null;

            return response()->json([
                'active' => $selectedReportDate !== null && in_array($selectedReportDate, $activeDueDates, true),
                'active_due_dates' => $activeDueDates,
                'selected_report_date' => $selectedReportDate,
                'report' => $report ? $this->serializeReport($report->load('user:id,name,email')) : null,
            ]);
        }

        $reportsByUserId = $selectedReportDate
            ? SelfManagementReport::query()
                ->with('user:id,name,email,management_number,department')
                ->whereDate('report_date', $selectedReportDate)
                ->get()
                ->keyBy('user_id')
            : collect();

        $users = $this->activeUsersQuery()->get();

        return response()->json([
            'active' => $selectedReportDate !== null && in_array($selectedReportDate, $activeDueDates, true),
            'active_due_dates' => $activeDueDates,
            'selected_report_date' => $selectedReportDate,
            'reports' => $users->map(fn (User $user) => $this->serializeReportForUser($user, $reportsByUserId->get($user->id)))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $request->user();
        abort_if($viewer->isAdmin(), 403);

        $activeDueDates = $this->activeDueDates();
        $selectedReportDate = $this->selectedReportDate($request, $activeDueDates);
        if (! $selectedReportDate) {
            throw ValidationException::withMessages([
                'report_date' => ['自己管理レポートの提出期間ではありません。'],
            ]);
        }

        $data = $this->validateReportFields($request, $activeDueDates, true);

        $report = SelfManagementReport::query()->updateOrCreate(
            [
                'user_id' => $viewer->id,
                'report_date' => $data['report_date'] ?? $selectedReportDate,
            ],
            collect($data)->except('report_date')->all(),
        );
        $this->adminCommentForReport($report);

        return response()->json($this->serializeReport($report->load('user:id,name,email')), 201);
    }

    public function update(Request $request, SelfManagementReport $selfManagementReport)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'admin_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $selfManagementReport->update($data);

        return response()->json($this->serializeReport($selfManagementReport->load('user:id,name,email')));
    }

    private function activeDueDates(): array
    {
        $today = Carbon::today(config('app.timezone'));

        return CalendarEntry::query()
            ->where('type', 'self_report_due')
            ->whereDate('date', '<=', $today)
            ->whereDate('date', '>=', $today->copy()->subDays(6))
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->format('Y-m-d'))
            ->all();
    }

    private function selectedReportDate(Request $request, array $activeDueDates, bool $allowInactive = false): ?string
    {
        $requestedDate = $request->string('report_date')->toString();

        if ($requestedDate && in_array($requestedDate, $activeDueDates, true)) {
            return $requestedDate;
        }

        if ($allowInactive && $requestedDate && $this->isSelfReportDueDate($requestedDate)) {
            return $requestedDate;
        }

        if ($activeDueDates !== []) {
            return $activeDueDates[0];
        }

        return $allowInactive ? $this->latestSelfReportDueDate() : null;
    }

    private function isSelfReportDueDate(string $date): bool
    {
        return CalendarEntry::query()
            ->where('type', 'self_report_due')
            ->whereDate('date', $date)
            ->exists();
    }

    private function latestSelfReportDueDate(): ?string
    {
        $date = CalendarEntry::query()
            ->where('type', 'self_report_due')
            ->orderByDesc('date')
            ->value('date');

        return $date ? Carbon::parse($date)->format('Y-m-d') : null;
    }

    private function validateReportFields(Request $request, array $activeDueDates, bool $requireReportDate): array
    {
        return $request->validate([
            'report_date' => [$requireReportDate ? 'required' : 'nullable', 'date_format:Y-m-d', Rule::in($activeDueDates)],
            'work_rating' => ['nullable', 'string', 'max:255'],
            'life_rating' => ['nullable', 'string', 'max:255'],
            'monthly_reflection' => ['nullable', 'string', 'max:4000'],
            'next_month_goal' => ['nullable', 'string', 'max:4000'],
            'skill_progress' => ['nullable', 'string', 'max:4000'],
            'activity_status' => ['nullable', 'string', 'max:255'],
            'activity_detail' => ['nullable', 'string', 'max:4000'],
            'other' => ['nullable', 'string', 'max:4000'],
        ]);
    }

    private function serializeReportForUser(User $user, ?SelfManagementReport $report): array
    {
        $adminComment = $report ? $this->adminCommentForReport($report) : '';

        return [
            'id' => $report?->id,
            'user_id' => $user->id,
            'employee' => $user->name,
            'management_number' => $user->management_number ?? '',
            'department' => $user->department ?? '',
            'submitted' => $report !== null,
            'report_date' => $report?->report_date?->format('Y-m-d') ?? '',
            'work_rating' => $report?->work_rating ?? '',
            'life_rating' => $report?->life_rating ?? '',
            'monthly_reflection' => $report?->monthly_reflection ?? '',
            'next_month_goal' => $report?->next_month_goal ?? '',
            'skill_progress' => $report?->skill_progress ?? '',
            'activity_status' => $report?->activity_status ?? '',
            'activity_detail' => $report?->activity_detail ?? '',
            'other' => $report?->other ?? '',
            'admin_comment' => $adminComment,
            'submitted_at' => $report?->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
        ];
    }

    private function serializeReport(SelfManagementReport $report): array
    {
        $adminComment = $this->adminCommentForReport($report);

        return [
            'id' => $report->id,
            'user_id' => $report->user_id,
            'employee' => $report->user?->name,
            'submitted' => true,
            'report_date' => $report->report_date->format('Y-m-d'),
            'work_rating' => $report->work_rating ?? '',
            'life_rating' => $report->life_rating ?? '',
            'monthly_reflection' => $report->monthly_reflection ?? '',
            'next_month_goal' => $report->next_month_goal ?? '',
            'skill_progress' => $report->skill_progress ?? '',
            'activity_status' => $report->activity_status ?? '',
            'activity_detail' => $report->activity_detail ?? '',
            'other' => $report->other ?? '',
            'admin_comment' => $adminComment,
            'submitted_at' => $report->updated_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
        ];
    }

    private function adminCommentForReport(SelfManagementReport $report): string
    {
        if (filled($report->admin_comment)) {
            return $report->admin_comment;
        }

        $content = $this->reportContentForComment($report);

        if (blank($content)) {
            return '';
        }

        $comment = $this->generateAdminComment($content);
        $report->forceFill(['admin_comment' => $comment])->save();

        return $comment;
    }

    private function reportContentForComment(SelfManagementReport $report): string
    {
        return collect([
            '仕事評価' => $report->work_rating,
            '生活評価' => $report->life_rating,
            '当月の振り返り' => $report->monthly_reflection,
            '来月の目標' => $report->next_month_goal,
            '一般就労に向けたスキルアップ' => $report->skill_progress,
            '一般就労に向けた行動' => $report->activity_status,
            '行動詳細' => $report->activity_detail,
            'その他事業所に伝えたいこと' => $report->other,
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, $label) => "{$label}: {$value}")
            ->implode("\n");
    }

    private function generateAdminComment(string $content): string
    {
        if (app()->environment('testing') || blank(config('services.openrouter.key'))) {
            return $this->fallbackAdminComment($content);
        }

        $cacheKey = 'self-management-report-comment:'.hash('sha256', config('services.openrouter.model').'|'.$content);

        return Cache::remember($cacheKey, now()->addDays(30), function () use ($content) {
            try {
                $response = Http::withToken(config('services.openrouter.key'))
                    ->acceptJson()
                    ->asJson()
                    ->withHeaders(array_filter([
                        'HTTP-Referer' => config('services.openrouter.referer'),
                        'X-Title' => config('services.openrouter.title'),
                    ]))
                    ->timeout(12)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => config('services.openrouter.model'),
                        'temperature' => 0.55,
                        'max_tokens' => 140,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'あなたは福祉事業所の管理者です。利用者の自己管理レポートに対して、自然で前向きな管理者コメントを日本語で1〜2文だけ返してください。断定しすぎず、本人の取り組みを認め、次につながる声かけにしてください。引用符や箇条書きは不要です。',
                            ],
                            [
                                'role' => 'user',
                                'content' => "自己管理レポート:\n{$content}",
                            ],
                        ],
                    ]);

                if (! $response->successful()) {
                    Log::warning('OpenRouter self management comment generation failed.', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return $this->fallbackAdminComment($content);
                }

                $comment = trim((string) data_get($response->json(), 'choices.0.message.content'));

                return $comment !== '' ? mb_substr($comment, 0, 240) : $this->fallbackAdminComment($content);
            } catch (\Throwable $exception) {
                Log::warning('OpenRouter self management comment generation exception.', [
                    'message' => $exception->getMessage(),
                ]);

                return $this->fallbackAdminComment($content);
            }
        });
    }

    private function fallbackAdminComment(string $content): string
    {
        if (str_contains($content, '全く頑張れていない') || str_contains($content, 'いいえ')) {
            return '率直に状況を共有できていることが大切です。無理のない範囲で、次に取り組めそうなことを一緒に確認していきましょう。';
        }

        if (str_contains($content, '毎日頑張れている') || str_contains($content, 'はい')) {
            return '日々の取り組みがしっかり継続できている様子が伝わります。この調子で、できていることを積み重ねていきましょう。';
        }

        return '今月の状況を丁寧に振り返ることができています。来月も無理なく続けられる目標を意識して進めていきましょう。';
    }

    private function activeUsersQuery()
    {
        return User::query()
            ->where('role', 'user')
            ->where(function ($query) {
                $query
                    ->whereNull('retirement_date')
                    ->orWhereDate('retirement_date', '>=', today(config('app.timezone')));
            })
            ->orderBy('department_display_order')
            ->orderBy('department')
            ->orderBy('display_order')
            ->orderBy('id');
    }
}
