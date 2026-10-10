<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\PushDevice;
use App\Models\Task;
use Illuminate\Http\Request;

class MobileNotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1']);
        $alerts = Alert::where('user_id', $request->user()->id)->latest('id')->paginate(20);
        $visibleIds = Task::visible($request->user())->whereIn('id', $alerts->pluck('task_id')->filter())->pluck('id');

        return response()->json(['data' => $alerts->getCollection()->map(fn ($a) => [
            'id' => $a->id, 'type' => $a->type, 'message' => $a->message,
            'task_id' => $visibleIds->contains($a->task_id) ? $a->task_id : null,
            'created_at' => $a->created_at->toIso8601String(), 'read_at' => $a->read_at?->toIso8601String(),
        ]), 'meta' => ['page' => $alerts->currentPage(), 'last_page' => $alerts->lastPage(), 'total' => $alerts->total(), 'unread' => Alert::where('user_id', $request->user()->id)->whereNull('read_at')->count()]]);
    }

    public function read(Alert $alert, Request $request)
    {
        abort_unless($alert->user_id === $request->user()->id, 403);
        $alert->update(['read_at' => $alert->read_at ?? now()]);

        return response()->json(['message' => 'Notification marked read.']);
    }

    public function register(Request $request)
    {
        $v = $request->validate(['platform' => 'required|in:android,ios', 'token' => 'required|string|max:4096']);
        if ($v['platform'] === 'ios') {
            $request->validate(['token' => 'regex:/^[a-fA-F0-9]{64,200}$/']);
        }
        PushDevice::updateOrCreate(['token_hash' => hash('sha256', $v['token'])], [
            'user_id' => $request->user()->id, 'personal_access_token_id' => $request->user()->currentAccessToken()->id,
            'platform' => $v['platform'], 'token' => $v['token'],
        ]);

        return response()->json(['message' => 'Push device registered.']);
    }

    public function unregister(Request $request)
    {
        PushDevice::where('personal_access_token_id', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Push disabled for this sign-in.']);
    }
}
