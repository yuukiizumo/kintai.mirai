<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $admins = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => $this->serializeAdmin($user));

        return response()->json([
            'admins' => $admins,
        ]);
    }

    public function update(Request $request, User $user)
    {
        abort_unless($request->user()->isStrongAdmin(), 403);
        abort_unless($user->role === 'admin', 404);

        $data = $request->validate([
            'admin_level' => ['required', Rule::in(['strong', 'weak'])],
        ]);

        if ($user->is($request->user()) && $data['admin_level'] === 'weak') {
            abort(422, '自分自身を弱管理者には変更できません。');
        }

        if (($user->admin_level ?? 'strong') === 'strong' && $data['admin_level'] === 'weak') {
            $strongAdminCount = User::query()
                ->where('role', 'admin')
                ->where(function ($query) {
                    $query->where('admin_level', 'strong')
                        ->orWhereNull('admin_level');
                })
                ->count();

            abort_unless($strongAdminCount > 1, 422, '強管理者は最低1人必要です。');
        }

        $user->update([
            'admin_level' => $data['admin_level'],
        ]);

        return response()->json($this->serializeAdmin($user->refresh()));
    }

    private function serializeAdmin(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'admin_level' => $user->admin_level ?? 'strong',
            'admin_level_label' => ($user->admin_level ?? 'strong') === 'strong' ? '強管理者' : '弱管理者',
            'created_at' => $user->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '',
        ];
    }
}
