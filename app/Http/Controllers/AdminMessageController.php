<?php

namespace App\Http\Controllers;

use App\Models\AdminMessage;
use Illuminate\Http\Request;

class AdminMessageController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();

        $query = AdminMessage::query()
            ->with(['admin:id,name', 'user:id,name'])
            ->whereNull('user_id')
            ->latest();

        return response()->json([
            'messages' => $query->limit(10)->get()->map(fn (AdminMessage $message) => $this->serializeMessage($message)),
        ]);
    }

    public function store(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdmin(), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $message = AdminMessage::query()->create([
            'user_id' => null,
            'admin_id' => $viewer->id,
            'body' => $data['body'],
        ]);

        return response()->json($this->serializeMessage($message->load(['admin:id,name', 'user:id,name'])), 201);
    }

    private function serializeMessage(AdminMessage $message): array
    {
        return [
            'id' => $message->id,
            'user_id' => $message->user_id,
            'employee' => $message->user?->name,
            'admin' => $message->admin?->name,
            'body' => $message->body,
            'sent_at' => $message->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            'is_collapsed_default' => $message->created_at?->lt(now()->subDays(3)) ?? false,
        ];
    }
}
