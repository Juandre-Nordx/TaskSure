@extends('layout')
@section('title','Activity details')
@section('content')
@include('platform.tabs')
<div class="pagehead"><div><p class="eyebrow">READ-ONLY EVENT #{{ $log->id }}</p><h1>{{ $log->actionLabel() }}</h1></div><a class="btn" href="/super-admin/activity-logs">Back to activity logs</a></div>
<section class="card" style="margin-bottom:22px"><div class="form-grid">
    <div><p class="eyebrow">Date/time · SAST</p><p>{{ \App\Support\BusinessTime::show($log->occurred_at,'d M Y, H:i:s') }}</p></div>
    <div><p class="eyebrow">Result</p><p>{{ ucfirst($log->result) }}</p></div>
    <div><p class="eyebrow">User</p><p>{{ $log->actor_name }} · {{ $log->actor_email ?? 'No account email' }}{{ $log->actor_id ? ' · User #'.$log->actor_id : '' }}</p><p class="field-help">{{ ['authenticated'=>'Authenticated actor','claimed'=>'Claimed sign-in account; identity was not verified','system'=>'Console / automated system action','historical'=>'Historical attribution; role at the event time is unknown','unknown'=>'Unauthenticated request; identity was not verified'][$log->attribution] ?? $log->attribution }}</p></div>
    <div><p class="eyebrow">Role at event</p><p>{{ \App\Models\ActivityLog::ROLES[$log->actor_role] ?? 'System / unknown' }}</p></div>
    <div><p class="eyebrow">Company / store</p><p>{{ $log->company_name ?? 'Platform / unknown' }}{{ $log->company_id ? ' (#'.$log->company_id.')' : '' }}<br>{{ $log->store_name ?? 'Company-wide / no store' }}{{ $log->store_id ? ' (#'.$log->store_id.')' : '' }}</p></div>
    <div><p class="eyebrow">Affected record</p><p>{{ $log->record_label }}<br>{{ str_replace('_',' ',$log->record_type) }}{{ $log->record_id ? ' #'.$log->record_id : '' }}</p></div>
    <div><p class="eyebrow">Source / category</p><p>{{ $log->source }} · {{ \App\Models\ActivityLog::CATEGORIES[$log->category] }}</p></div>
    <div><p class="eyebrow">Action code</p><p>{{ $log->action }}</p></div>
</div></section>
<section class="card" style="margin-bottom:22px"><h2>Before and after</h2><p class="field-help">Only relevant, safe metadata is recorded. Credentials, file/policy contents, private paths and free-form completion/comment notes are excluded. Missing historical values stay unknown.</p>
    <div class="table-wrap"><table><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
    @forelse(array_unique(array_merge(array_keys($log->before_values ?? []),array_keys($log->after_values ?? []))) as $key)
        <tr><td>{{ ucfirst(str_replace('_',' ',$key)) }}</td><td>{{ $log->displayValue($key,$log->before_values[$key] ?? null) }}</td><td>{{ $log->displayValue($key,$log->after_values[$key] ?? null) }}</td></tr>
    @empty
        <tr><td colspan="3" class="empty">No before/after values were recorded for this event.</td></tr>
    @endforelse
    </tbody></table></div>
</section>
<section class="card"><h2>Event details</h2>
    @forelse($log->details ?? [] as $key=>$value)<div style="margin-top:14px"><p class="eyebrow">{{ str_replace('_',' ',$key) }}</p><p class="note">{{ $log->displayValue($key,$value) }}</p></div>@empty<p class="field-help">No additional details.</p>@endforelse
    <p class="field-help" style="margin-top:18px">A served PDF records a server response, not proof of reading or successful saving on a phone. Policy acknowledgement is a separate recorded action.</p>
</section>
@endsection
