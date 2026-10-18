@extends('layout')
@section('title','Activity logs')
@section('content')
@include('platform.tabs')
<div class="pagehead"><div><p class="eyebrow">PLATFORM RECORDS</p><h1>Activity logs</h1><p class="muted" style="margin-top:10px">Trace meaningful server actions across web and mobile. Times use Africa/Johannesburg (SAST).</p></div></div>
<section class="card" style="margin-bottom:22px">
    <form method="get" action="/super-admin/activity-logs" class="stack">
        <div class="filter-bar">
            <div><label for="start">Start date/time · SAST</label><input id="start" name="start" type="datetime-local" value="{{ $filters['start'] ?? '' }}"></div>
            <div><label for="end">End date/time · SAST</label><input id="end" name="end" type="datetime-local" value="{{ $filters['end'] ?? '' }}"></div>
            <div><label for="company">Company</label><select id="company" name="company_id"><option value="">All companies</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id'] ?? '')==$company->id)>{{ $company->name }}</option>@endforeach</select></div>
            <div><label for="store">Store</label><select id="store" name="store_id"><option value="">All authorised stores</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(($filters['store_id'] ?? '')==$store->id)>{{ $store->name }} · {{ $store->company->name }}{{ $store->active ? '' : ' · Inactive' }}</option>@endforeach</select></div>
        </div>
        <div class="filter-bar">
            <div><label for="acting-user">Acting user · name or email</label><input id="acting-user" name="user" maxlength="255" value="{{ $filters['user'] ?? '' }}" placeholder="Name or email"></div>
            <div><label for="role">Actor role</label><select id="role" name="role"><option value="">All roles</option>@foreach(\App\Models\ActivityLog::ROLES as $key=>$label)<option value="{{ $key }}" @selected(($filters['role'] ?? '')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="category">Category</label><select id="category" name="category"><option value="">All categories</option>@foreach(\App\Models\ActivityLog::CATEGORIES as $key=>$label)<option value="{{ $key }}" @selected(($filters['category'] ?? '')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="action">Action</label><select id="action" name="action"><option value="">All actions</option>@foreach(\App\Models\ActivityLog::ACTIONS as $key=>$label)<option value="{{ $key }}" @selected(($filters['action'] ?? '')===$key)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="result">Result</label><select id="result" name="result"><option value="">All results</option><option value="success" @selected(($filters['result'] ?? '')==='success')>Success</option><option value="failure" @selected(($filters['result'] ?? '')==='failure')>Failure</option></select></div>
        </div>
        <div class="filter-bar"><div><label for="q">Search events</label><input id="q" name="q" maxlength="180" value="{{ $filters['q'] ?? '' }}" placeholder="Task title, record ID or safe event details"></div><button class="btn primary" type="submit">Apply filters</button><a class="btn" href="/super-admin/activity-logs">Clear</a></div>
    </form>
    <p class="field-help">Date/time limits include every second in the selected end minute. To change companies, clear a selected store first. Evidence uploads and deletions are under Tasks; store changes are under Settings.</p>
</section>
<section class="card">
    <div class="card-head"><h2>Recorded events</h2><span class="subline">{{ number_format($logs->total()) }} matching events · 25 per page</span></div>
    <p class="field-help">Full tracking begins when this update is installed. Imported events are marked Historical; missing earlier events are not reconstructed. Failed sign-ins identify the claimed account, not a verified person. Logs are read-only.</p>
    <div class="table-wrap"><table><thead><tr><th>Date/time · SAST</th><th>User</th><th>Role</th><th>Company / store</th><th>Category / action</th><th>Affected record</th><th>Result</th><th>Details</th></tr></thead><tbody>
    @forelse($logs as $log)
        <tr>
            <td>{{ \App\Support\BusinessTime::show($log->occurred_at,'d M Y, H:i:s') }}<br><span class="subline">{{ ['mobile'=>'Mobile API','web'=>'Web','console'=>'Console / system','scheduler'=>'Scheduler','history'=>'Historical'][$log->source] ?? $log->source }}</span></td>
            <td><strong>{{ $log->actor_name }}</strong><br><span class="subline">{{ $log->actor_email ?? 'No account email' }}</span>@if($log->attribution==='claimed')<p class="field-help">Unverified sign-in attempt</p>@endif</td>
            <td>{{ \App\Models\ActivityLog::ROLES[$log->actor_role] ?? 'System / unknown' }}</td>
            <td>{{ $log->company_name ?? 'Platform / unknown' }}<br><span class="subline">{{ $log->store_name ?? 'Company-wide / no store' }}</span></td>
            <td>{{ $log->actionLabel() }}<br><span class="subline">{{ \App\Models\ActivityLog::CATEGORIES[$log->category] }}</span></td>
            <td>{{ $log->record_label }}<br><span class="subline">{{ str_replace('_',' ',$log->record_type) }}{{ $log->record_id ? ' #'.$log->record_id : '' }}</span></td>
            <td><span class="badge {{ $log->result==='failure'?'cancelled':'completed' }}">{{ ucfirst($log->result) }}</span></td>
            <td><a class="btn" href="/super-admin/activity-logs/{{ $log->id }}">View details</a></td>
        </tr>
    @empty
        <tr><td colspan="8" class="empty">No events match these filters.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $logs->links() }}
</section>
@endsection
