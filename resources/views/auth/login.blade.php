<!doctype html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $title }} | 勤怠管理</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-[#f3f8fc] text-slate-950">
        <main class="flex min-h-screen items-center justify-center px-4 py-10">
            <section class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-medium text-sky-700">Attendance Manager</p>
                <h1 class="mt-1 text-2xl font-semibold">{{ $title }}</h1>
                <p class="mt-2 text-sm text-slate-600">
                    {{ $role === 'admin' ? '管理者は全従業員の勤怠を確認できます。' : '一般ユーザーは自分の勤怠だけを確認できます。' }}
                </p>
                @if (session('status'))
                    <p class="mt-4 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-800">{{ session('status') }}</p>
                @endif

                <form class="mt-6 grid gap-4" method="POST" action="{{ $action }}">
                    @csrf
                    <label class="field-label">
                        メールアドレス
                        <input class="field-control" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required autofocus>
                        @error('email')
                            <span class="text-xs font-medium text-rose-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="field-label">
                        パスワード
                        <input class="field-control" type="password" name="password" value="password" autocomplete="current-password" required>
                        @error('password')
                            <span class="text-xs font-medium text-rose-600">{{ $message }}</span>
                        @enderror
                    </label>

                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700">
                        <input class="size-4 rounded border-slate-300 text-sky-700 focus:ring-sky-200" type="checkbox" name="remember" value="1">
                        ログイン状態を保持
                    </label>

                    <button class="primary-button" type="submit">ログイン</button>
                </form>
                <div class="mt-3 text-right text-sm">
                    <a class="font-semibold text-sky-700 hover:text-sky-800" href="{{ route('password.request') }}">パスワードを忘れた方</a>
                </div>
                <div class="mt-4">
                    @if ($role === 'admin')
                        <a class="secondary-button w-full justify-center" href="{{ route('admin.register') }}">管理者新規登録</a>
                    @else
                        <a class="secondary-button w-full justify-center" href="{{ route('register') }}">新規登録</a>
                    @endif
                </div>

                <div class="mt-5 border-t border-slate-200 pt-4 text-sm">
                    @if ($role === 'admin')
                        <a class="font-semibold text-sky-700 hover:text-sky-800" href="{{ route('login') }}">一般ユーザーログインへ</a>
                    @else
                        <a class="font-semibold text-sky-700 hover:text-sky-800" href="{{ route('admin.login') }}">管理者ログインへ</a>
                    @endif
                </div>
            </section>
        </main>
    </body>
</html>

