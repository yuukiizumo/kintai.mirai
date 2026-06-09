<!doctype html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>&#12497;&#12473;&#12527;&#12540;&#12489;&#12434;&#24536;&#12428;&#12383;&#26041; | &#21220;&#24608;&#31649;&#29702;</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="bg-[#f3f8fc] text-slate-950">
        <main class="flex min-h-screen items-center justify-center px-4 py-10">
            <section class="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-sm font-medium text-sky-700">Attendance Manager</p>
                <h1 class="mt-1 text-2xl font-semibold">&#12497;&#12473;&#12527;&#12540;&#12489;&#12434;&#24536;&#12428;&#12383;&#26041;</h1>
                <p class="mt-2 text-sm text-slate-600">
                    &#30331;&#37682;&#28168;&#12415;&#12513;&#12540;&#12523;&#12450;&#12489;&#12524;&#12473;&#12434;&#20837;&#21147;&#12375;&#12390;&#12367;&#12384;&#12373;&#12356;&#12290;&#12497;&#12473;&#12527;&#12540;&#12489;&#20877;&#35373;&#23450;&#12522;&#12531;&#12463;&#12434;&#12513;&#12540;&#12523;&#12391;&#36865;&#20449;&#12375;&#12414;&#12377;&#12290;
                </p>

                @if (session('status'))
                    <p class="mt-4 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-800">
                        &#12497;&#12473;&#12527;&#12540;&#12489;&#20877;&#35373;&#23450;&#12522;&#12531;&#12463;&#12434;&#12513;&#12540;&#12523;&#12391;&#36865;&#20449;&#12375;&#12414;&#12375;&#12383;&#12290;&#12513;&#12540;&#12523;&#12434;&#12372;&#30906;&#35469;&#12367;&#12384;&#12373;&#12356;&#12290;
                    </p>
                @endif

                <form class="mt-6 grid gap-4" method="POST" action="{{ route('password.email') }}">
                    @csrf
                    <label class="field-label">
                        &#12513;&#12540;&#12523;&#12450;&#12489;&#12524;&#12473;
                        <input class="field-control" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
                        @error('email')<span class="text-xs font-medium text-rose-600">{{ $message }}</span>@enderror
                    </label>

                    <button class="primary-button" type="submit">&#20877;&#35373;&#23450;&#12434;&#30003;&#35531;</button>
                </form>

                <div class="mt-5 border-t border-slate-200 pt-4 text-sm">
                    <a class="font-semibold text-sky-700 hover:text-sky-800" href="{{ route('login') }}">&#12525;&#12464;&#12452;&#12531;&#12408;&#25147;&#12427;</a>
                </div>
            </section>
        </main>
    </body>
</html>
