<!doctype html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>新規登録 | 勤怠管理</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-[#f6f8f5] text-slate-950">
        <main class="mx-auto min-h-screen w-full max-w-5xl px-4 py-8 sm:px-6">
            <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 border-b border-slate-200 pb-5 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-sm font-medium text-emerald-700">Attendance Manager</p>
                        <h1 class="mt-1 text-2xl font-semibold">新規登録</h1>
                    </div>
                    <a class="secondary-button" href="{{ route('login') }}">ログインへ戻る</a>
                </div>

                <form class="mt-6 grid gap-6" method="POST" action="{{ route('register.store') }}">
                    @csrf

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <label class="field-label">
                            氏名
                            <input class="field-control" name="name" value="{{ old('name') }}" required autofocus>
                            @error('name')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            入社日
                            <input class="field-control" type="date" name="hire_date" value="{{ old('hire_date') }}">
                            @error('hire_date')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            メールアドレス
                            <input class="field-control" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>
                            @error('email')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            パスワード
                            <input class="field-control" type="password" name="password" autocomplete="new-password" required>
                            @error('password')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            パスワード確認
                            <input class="field-control" type="password" name="password_confirmation" autocomplete="new-password" required>
                        </label>

                        <label class="field-label">
                            部署
                            <select id="department" class="field-control" name="department">
                                @foreach (array_keys($departments) as $department)
                                    <option value="{{ $department }}" @selected(old('department', '新今宮') === $department)>{{ $department }}</option>
                                @endforeach
                            </select>
                            @error('department')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            業務区分
                            <select id="business_category" class="field-control" name="business_category" data-current="{{ old('business_category') }}"></select>
                            @error('business_category')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            業務形態
                            <select class="field-control" name="work_style">
                                <option value="A型" @selected(old('work_style', 'A型') === 'A型')>A型</option>
                                <option value="B型" @selected(old('work_style') === 'B型')>B型</option>
                            </select>
                            @error('work_style')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            通所上限日数
                            <select class="field-control" name="commute_limit_days">
                                <option value="-8日" @selected(old('commute_limit_days', '-8日') === '-8日')>-8日</option>
                                <option value="-4日" @selected(old('commute_limit_days') === '-4日')>-4日</option>
                            </select>
                            @error('commute_limit_days')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            身長
                            <input class="field-control" type="number" min="0" step="0.1" name="height_cm" value="{{ old('height_cm') }}">
                            @error('height_cm')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            体重
                            <input class="field-control" type="number" min="0" step="0.1" name="weight_kg" value="{{ old('weight_kg') }}">
                            @error('weight_kg')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>

                        <label class="field-label">
                            性別
                            <select class="field-control" name="gender">
                                <option value="男" @selected(old('gender', '男') === '男')>男</option>
                                <option value="女" @selected(old('gender') === '女')>女</option>
                            </select>
                            @error('gender')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                <tr>
                                    <th class="table-cell">曜日</th>
                                    <th class="table-cell">勤務しない</th>
                                    <th class="table-cell">デフォルト出勤時刻</th>
                                    <th class="table-cell">デフォルト退勤時刻</th>
                                    <th class="table-cell">デフォルト休憩時間（分）</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($weekdays as $weekday => $label)
                                    <tr>
                                        <td class="table-cell font-semibold">{{ $label }}</td>
                                        <td class="table-cell">
                                            <input type="hidden" name="workday_settings[{{ $weekday }}][is_working_day]" value="1">
                                            <input
                                                class="size-4 accent-emerald-600"
                                                type="checkbox"
                                                name="workday_settings[{{ $weekday }}][is_working_day]"
                                                value="0"
                                                @checked(old("workday_settings.$weekday.is_working_day", '1') === '0')
                                            >
                                        </td>
                                        <td class="table-cell">
                                            <input class="field-control w-36" type="time" name="workday_settings[{{ $weekday }}][default_clock_in]" value="{{ old("workday_settings.$weekday.default_clock_in", '09:00') }}" required>
                                        </td>
                                        <td class="table-cell">
                                            <input class="field-control w-36" type="time" name="workday_settings[{{ $weekday }}][default_clock_out]" value="{{ old("workday_settings.$weekday.default_clock_out", '18:00') }}" required>
                                        </td>
                                        <td class="table-cell">
                                            <input class="field-control w-32" type="number" min="0" max="600" name="workday_settings[{{ $weekday }}][default_break_minutes]" value="{{ old("workday_settings.$weekday.default_break_minutes", 60) }}" required>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end">
                        <button class="primary-button" type="submit">登録する</button>
                    </div>
                </form>
            </section>
        </main>

        <script>
            const businessCategoriesByDepartment = @json($departments);
            const departmentSelect = document.getElementById('department');
            const businessCategorySelect = document.getElementById('business_category');

            function refreshBusinessCategories() {
                const options = businessCategoriesByDepartment[departmentSelect.value] || businessCategoriesByDepartment['新今宮'];
                const currentValue = businessCategorySelect.dataset.current || businessCategorySelect.value;

                businessCategorySelect.innerHTML = '';
                options.forEach((option) => {
                    const element = document.createElement('option');
                    element.value = option;
                    element.textContent = option;
                    element.selected = option === currentValue;
                    businessCategorySelect.appendChild(element);
                });

                if (!options.includes(currentValue)) {
                    businessCategorySelect.value = options[0];
                }

                businessCategorySelect.dataset.current = businessCategorySelect.value;
            }

            departmentSelect.addEventListener('change', () => {
                businessCategorySelect.dataset.current = '';
                refreshBusinessCategories();
            });
            refreshBusinessCategories();
        </script>
    </body>
</html>
