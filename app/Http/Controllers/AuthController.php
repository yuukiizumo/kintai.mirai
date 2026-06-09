<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetLinkMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const ADMIN_REGISTRATION_PASSPHRASE = 'iamadmin';

    public function showUserLogin(): View
    {
        return view('auth.login', [
            'title' => 'ユーザーログイン',
            'action' => route('login.store'),
            'email' => 'hanako@example.com',
            'role' => 'user',
        ]);
    }

    public function showAdminLogin(): View
    {
        return view('auth.login', [
            'title' => '管理者ログイン',
            'action' => route('admin.login.store'),
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
    }

    public function showRegister(): View
    {
        return view('auth.register', [
            'departments' => $this->businessCategoriesByDepartment(),
            'weekdays' => [
                '1' => '月',
                '2' => '火',
                '3' => '水',
                '4' => '木',
                '5' => '金',
                '6' => '土',
            ],
        ]);
    }

    public function showAdminRegister(): View
    {
        return view('auth.admin-register');
    }

    public function showForgotPassword(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        if (User::query()->where('email', $data['email'])->exists()) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $data['email']],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ],
            );

            $resetUrl = route('password.reset', [
                'token' => $token,
                'email' => $data['email'],
            ]);

            Mail::to($data['email'])->send(new PasswordResetLinkMail($resetUrl));
        }

        return redirect()
            ->route('password.request')
            ->with('status', 'password_reset_requested');
    }

    public function showResetPassword(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $resetToken = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        if (! $resetToken
            || ! Hash::check($data['token'], $resetToken->token)
            || Carbon::parse($resetToken->created_at)->lt(now()->subMinutes(60))) {
            throw ValidationException::withMessages([
                'email' => 'パスワード再設定リンクが無効、または期限切れです。',
            ]);
        }

        User::query()
            ->where('email', $data['email'])
            ->firstOrFail()
            ->update([
                'password' => $data['password'],
            ]);

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return redirect()->route('login')->with('status', 'パスワードを再設定しました。新しいパスワードでログインしてください。');
    }

    public function registerAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'passphrase' => ['required', 'string'],
        ]);

        if ($data['passphrase'] !== self::ADMIN_REGISTRATION_PASSPHRASE) {
            throw ValidationException::withMessages([
                'passphrase' => '合言葉が違います。',
            ]);
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => 'admin',
            'admin_level' => 'strong',
            'password' => $data['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    public function register(Request $request): RedirectResponse
    {
        $this->prepareRegistrationInput($request);

        $departmentOptions = array_keys($this->businessCategoriesByDepartment());
        $businessCategoryOptions = collect($this->businessCategoriesByDepartment())->flatten()->unique()->values()->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
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
            'department' => ['nullable', Rule::in($departmentOptions)],
            'business_category' => ['nullable', Rule::in($businessCategoryOptions)],
            'work_style' => ['nullable', Rule::in(['A型', 'B型'])],
            'commute_limit_days' => ['nullable', Rule::in(['-8日', '-4日'])],
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:999.9'],
            'gender' => ['nullable', Rule::in(['男', '女'])],
        ]);

        $mondaySettings = $data['workday_settings'][1] ?? $data['workday_settings']['1'];
        $hireDate = filled($data['hire_date'] ?? null) ? Carbon::parse($data['hire_date']) : null;

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => 'user',
            'password' => $data['password'],
            'hire_date' => $hireDate,
            'department' => $data['department'] ?? '新今宮',
            'business_category' => $data['business_category'] ?? '軽作業',
            'work_style' => $data['work_style'] ?? 'A型',
            'commute_limit_days' => $data['commute_limit_days'] ?? '-8日',
            'height_cm' => $data['height_cm'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'gender' => $data['gender'] ?? '男',
            'default_clock_in' => $mondaySettings['default_clock_in'],
            'default_clock_out' => $mondaySettings['default_clock_out'],
            'default_break_minutes' => $mondaySettings['default_break_minutes'],
            'workday_settings' => $data['workday_settings'],
        ]);

        $user->update([
            'paid_leave_remaining_days' => $user->calculatedPaidLeaveRemainingDays(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    public function login(Request $request, string $role): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが違います。',
            ]);
        }

        $request->session()->regenerate();

        if (Auth::user()->role !== $role) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => $role === 'admin'
                    ? '管理者アカウントでログインしてください。'
                    : '一般ユーザーアカウントでログインしてください。',
            ]);
        }

        if ($role === 'user' && Auth::user()->isEffectivelyRetired()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => '退職扱いのためログインできません。',
            ]);
        }

        return redirect()->route('home');
    }

    public function logout(Request $request): RedirectResponse
    {
        $wasAdmin = Auth::user()?->isAdmin() ?? false;

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($wasAdmin ? 'admin.login' : 'login');
    }

    private function prepareRegistrationInput(Request $request): void
    {
        $inputSettings = $request->input('workday_settings', []);
        $workdaySettings = collect(range(1, 6))
            ->mapWithKeys(function (int $weekday) use ($inputSettings) {
                $key = (string) $weekday;
                $input = $inputSettings[$key] ?? $inputSettings[$weekday] ?? [];

                return [
                    $key => [
                        'default_clock_in' => blank($input['default_clock_in'] ?? null) ? '09:00' : $input['default_clock_in'],
                        'default_clock_out' => blank($input['default_clock_out'] ?? null) ? '18:00' : $input['default_clock_out'],
                        'default_break_minutes' => blank($input['default_break_minutes'] ?? null) ? 60 : $input['default_break_minutes'],
                        'is_working_day' => filter_var($input['is_working_day'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    ],
                ];
            })
            ->all();

        $department = $request->input('department') ?: '新今宮';
        $businessCategories = $this->businessCategoriesByDepartment();
        $businessCategoryOptions = $businessCategories[$department] ?? $businessCategories['新今宮'];
        $businessCategory = $request->input('business_category');

        $request->merge([
            'workday_settings' => $workdaySettings,
            'department' => $department,
            'business_category' => in_array($businessCategory, $businessCategoryOptions, true) ? $businessCategory : $businessCategoryOptions[0],
            'work_style' => $request->input('work_style') ?: 'A型',
            'commute_limit_days' => $request->input('commute_limit_days') ?: '-8日',
            'gender' => $request->input('gender') ?: '男',
        ]);
    }

    private function businessCategoriesByDepartment(): array
    {
        return [
            '新今宮' => ['軽作業', '配送その他'],
            '日本橋' => ['軽作業', '配送その他'],
            '南船場' => ['物作り', '軽作業その他'],
            '阿倍野事務' => ['事務'],
            '阿倍野弁当' => ['弁当', '軽作業その他'],
            '在宅' => ['在宅事務', '在宅PC', '県外PC', '関東PC', '関西PC'],
            'フリーケア' => ['マッサージ'],
        ];
    }
}
