<!doctype html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>管理者新規登録 | 勤怠管理</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-[#f6f8f5] text-slate-950">
        <main class="flex min-h-screen items-center justify-center px-4 py-10">
            <section class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-medium text-emerald-700">Attendance Manager</p>
                <h1 class="mt-1 text-2xl font-semibold">管理者新規登録</h1>
                <p class="mt-2 text-sm text-slate-600">管理者アカウントを作成します。</p>

                <form class="mt-6 grid gap-4" method="POST" action="{{ route('admin.register.store') }}">
                    @csrf

                    <label class="field-label">
                        名前
                        <input class="field-control" name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
                        @error('name')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
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
                        合言葉
                        <input class="field-control" type="password" name="passphrase" required>
                        @error('passphrase')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <button class="primary-button" type="submit">登録する</button>
                </form>

                <div class="mt-5 border-t border-slate-200 pt-4 text-sm">
                    <a class="font-semibold text-emerald-700 hover:text-emerald-800" href="{{ route('admin.login') }}">管理者ログインへ戻る</a>
                </div>
            </section>
        </main>
    </body>
</html>
