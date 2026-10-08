
@extends('layout')
@section('title','Reports')
@section('content')<div class="pagehead"><div><p class="eyebrow">CLEAR RECORDS, FAIR CONTEXT</p><h1>{{ request('employee_id')?'Employee work report':'Employee comparison' }}</h1><p class="muted" style="margin-top:9px">Assignments, submissions, and outcomes. Every number has a basis.</p></div><div class="small-links"><a class="btn" href="{{ request()->fullUrlWithQuery(['format'=>'pdf']) }}">↓ PDF</a><a class="btn primary" href="{{ request()->fullUrlWithQuery(['format'=>'xlsx']) }}">↓ Excel</a></div></div><section class="card" style="margin-bottom:22px"><form method="get">
@include('partials.filters')<div class="filter-bar"><div><label>Date basis</label><select name="basis">
@foreach(['due'=>'Tasks due in period','assigned'=>'Tasks assigned in period','approved'=>'Tasks approved in period'] as $k=>$v)<option value="{{ $k }}" 
@selected(request('basis','due')===$k)>{{ $v }}</option>
@endforeach</select></div><div><label>From · SAST</label><input type="date" name="from" value="{{ request('from') }}"></div><div><label>To · SAST</label><input type="date" name="to" value="{{ request('to') }}"></div></div></form><details><summary>How these metrics are calculated</summary><div class="muted" style="line-height:1.8;font-size:12px">
@include('reports.definitions')</div></details></section><section class="card" style="margin-bottom:22px"><div class="card-head"><h2>Comparison · selected task cohort</h2><span class="subline">{{ count($rows) }} tasks selected</span></div><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Selected tasks</th><th>Approved</th><th>Open</th><th>Review</th><th>First on time</th><th>First late</th><th>On-time rate</th><th>Overdue</th><th>Corrections</th></tr></thead><tbody>
@forelse($summary as $s)<tr><td><strong>{{ $s['employee'] }}</strong></td><td>{{ $s['assigned'] }}</td><td>{{ $s['approved'] }}</td><td>{{ $s['pending'] }}</td><td>{{ $s['awaiting_review'] }}</td><td>{{ $s['first_on_time'] }}</td><td>{{ $s['first_late'] }}</td><td>{{ $s['submitted_denominator']?round(100*$s['first_on_time']/$s['submitted_denominator'],1).'%':'—' }}<br><span class="subline">{{ $s['first_on_time'] }} / {{ $s['submitted_denominator'] }} submitted tasks</span></td><td>{{ $s['overdue'] }}</td><td>{{ $s['corrections'] }}</td></tr>
@empty<tr><td colspan="10" class="empty">No employees in this view.</td></tr>
@endforelse</tbody></table></div></section><section class="card"><h2>Individual task records</h2><p class="field-help">Open a record for evidence, attempts, blockers, and deadline changes.</p>
@forelse($rows as $row)<details style="border-bottom:1px solid var(--line);padding:10px 0"><summary>{{ $row['employee'] }} · {{ $row['title'] }} <span class="badge">{{ $row['status'] }}</span></summary><div class="form-grid" style="margin-top:15px">
@foreach($row as $key=>$value)
@if(!in_array($key,['id','title','employee']))<div class="{{ in_array($key,['attempts','deadline_changes','blockers','evidence'])?'wide':'' }}"><p class="eyebrow">{{ str_replace('_',' ',$key) }}</p>
@if($key==='evidence')
@foreach(explode(' | ',$value) as $url)
@if($url)<a class="btn" style="margin:5px" href="{{ $url }}">Evidence reference ↗</a>
@endif
@endforeach
@else<p class="note">{{ $value?:'—' }}</p>
@endif</div>
@endif
@endforeach</div><a class="btn" style="margin:15px 0" href="/tasks/{{ $row['id'] }}">Open task →</a></details>
@empty<div class="empty">No tasks match these filters. Try a different date range.</div>
@endforelse</section>
@endsection
