<!doctype html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>新しいパスワード | 勤怠管理</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-[#f3f8fc] text-slate-950">
        <main class="flex min-h-screen items-center justify-center px-4 py-10">
            <section class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-medium text-sky-700">Attendance Manager</p>
                <h1 class="mt-1 text-2xl font-semibold">新しいパスワード</h1>
                <p class="mt-2 text-sm text-slate-600">新しいパスワードを設定してください。</p>

                <form class="mt-6 grid gap-4" method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <label class="field-label">
                        メールアドレス
                        <input class="field-control" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required autofocus>
                        @error('email')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="field-label">
                        新しいパスワード
                        <input class="field-control" type="password" name="password" autocomplete="new-password" required>
                        @error('password')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <label class="field-label">
                        新しいパスワード（確認）
                        <input class="field-control" type="password" name="password_confirmation" autocomplete="new-password" required>
                    </label>

                    <button class="primary-button" type="submit">パスワードを再設定</button>
                </form>

                <div class="mt-5 border-t border-slate-200 pt-4 text-sm">
                    <a class="font-semibold text-sky-700 hover:text-sky-800" href="{{ route('login') }}">ログインへ戻る</a>
                </div>
            </section>
        </main>
    </body>
</html>
