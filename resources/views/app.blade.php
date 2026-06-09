<!doctype html>
<html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Attendance Manager</title>
        @php
            $user = auth()->user();
            $attendanceBootstrap = [
                'viewer' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'admin_level' => $user->admin_level ?? ($user->isAdmin() ? 'strong' : null),
                    'is_admin' => $user->isAdmin(),
                    'is_strong_admin' => $user->isStrongAdmin(),
                ] : null,
            ];
        @endphp
        <script>
            window.__ATTENDANCE_BOOTSTRAP__ = @json($attendanceBootstrap);
        </script>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    </head>
    <body>
        <div
            id="app"
            data-viewer-id="{{ $user?->id }}"
            data-viewer-name="{{ $user?->name }}"
            data-viewer-email="{{ $user?->email }}"
            data-viewer-role="{{ $user?->role }}"
            data-viewer-admin-level="{{ $user?->admin_level ?? ($user?->isAdmin() ? 'strong' : '') }}"
            data-viewer-is-admin="{{ $user?->isAdmin() ? 'true' : 'false' }}"
            data-viewer-is-strong-admin="{{ $user?->isStrongAdmin() ? 'true' : 'false' }}"
        >
            <main style="align-items:center;background:#f6f8f5;color:#0f172a;display:flex;justify-content:center;min-height:100vh;padding:1rem;">
                <section style="background:#fff;border:1px solid #e2e8f0;border-radius:.5rem;box-shadow:0 1px 2px rgba(15,23,42,.05);max-width:24rem;padding:1.5rem;text-align:center;width:100%;">
                    <p style="color:#047857;font-size:.875rem;font-weight:500;margin:0;">Attendance Manager</p>
                    <p style="color:#475569;font-size:.875rem;font-weight:600;margin:.75rem 0 0;">Loading...</p>
                </section>
            </main>
        </div>
    </body>
</html>
