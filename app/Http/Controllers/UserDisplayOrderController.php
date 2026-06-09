<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserDisplayOrderController extends Controller
{
    public function update(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'orders' => ['required', 'array'],
            'orders.*.user_id' => ['required', 'exists:users,id'],
            'orders.*.display_order' => ['required', 'integer', 'min:0', 'max:999999'],
            'orders.*.department_display_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'orders.*.department' => ['required', 'string', 'max:255'],
        ]);

        foreach ($data['orders'] as $order) {
            User::query()
                ->where('role', 'user')
                ->where(function ($query) {
                    $query
                        ->whereNull('retirement_date')
                        ->orWhereDate('retirement_date', '>=', today(config('app.timezone')));
                })
                ->whereKey($order['user_id'])
                ->update([
                    'display_order' => $order['display_order'],
                    'department_display_order' => $order['department_display_order'] ?? 0,
                    'department' => $order['department'],
                ]);
        }

        return response()->json([
            'message' => '表示順を保存しました。',
        ]);
    }
}
