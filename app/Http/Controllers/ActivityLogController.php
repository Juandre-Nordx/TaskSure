<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Store;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        $filters = $request->validate([
            'start' => 'nullable|date_format:Y-m-d\TH:i',
            'end' => array_filter(['nullable', 'date_format:Y-m-d\TH:i', $request->filled('start') ? 'after_or_equal:start' : null]),
            'company_id' => 'nullable|integer|exists:companies,id',
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->when($request->filled('company_id'), fn ($rule) => $rule->where('company_id', $request->input('company_id')))],
            'user' => 'nullable|string|max:255', 'role' => ['nullable', Rule::in(array_keys(ActivityLog::ROLES))],
            'category' => ['nullable', Rule::in(array_keys(ActivityLog::CATEGORIES))],
            'action' => ['nullable', Rule::in(array_keys(ActivityLog::ACTIONS))], 'result' => 'nullable|in:success,failure',
            'q' => 'nullable|string|max:180', 'page' => 'nullable|integer|min:1',
        ]);
        // Existing platform Super Admin has access to all store workspaces, including inactive stores.
        $query = ActivityLog::query();
        foreach (['company_id', 'store_id', 'category', 'action', 'result'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $query->where($key, $filters[$key]);
            }
        }
        if (filled($filters['role'] ?? null)) {
            $query->where('actor_role', $filters['role']);
        }
        if (filled($filters['user'] ?? null)) {
            $query->where(fn ($q) => $q->whereLike('actor_name', '%'.$filters['user'].'%')->orWhereLike('actor_email', '%'.$filters['user'].'%'));
        }
        if (filled($filters['q'] ?? null)) {
            $query->whereLike('search_text', '%'.$filters['q'].'%');
        }
        if (! empty($filters['start'])) {
            $query->where('occurred_at', '>=', BusinessTime::utc($filters['start']));
        }
        if (! empty($filters['end'])) {
            $query->where('occurred_at', '<', BusinessTime::utc($filters['end'])->addMinute());
        }
        $logs = $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return response()->view('platform.activity-logs', [
            'logs' => $logs, 'filters' => $filters, 'companies' => Company::orderBy('name')->get(),
            'stores' => Store::when(! empty($filters['company_id']), fn ($q) => $q->where('company_id', $filters['company_id']))->with('company')->orderBy('name')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $log)
    {
        abort_unless($request->user()->role === 'super_admin', 403);
        // Look up an event only after authorization, so other roles cannot probe its existence.
        $id = filter_var($log, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $log = ActivityLog::findOrFail($id);

        return response()->view('platform.activity-details', ['log' => $log])->header('Cache-Control', 'private, no-store');
    }
}
