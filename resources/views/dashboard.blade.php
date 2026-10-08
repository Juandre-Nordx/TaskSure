
@extends('layout')
@section('title','Overview')
@section('content')
<div class="pagehead"><div><p class="eyebrow">{{ now()->timezone('Africa/Johannesburg')->format('l, d F Y') }}</p><h1>Hello, {{ explode(' ',auth()->user()->name)[0] }}.</h1><p class="muted" style="margin-top:9px">{{ auth()->user()->isSupervisor()?"Here's how your store team is doing today.":"Here's your plan for a productive shift." }}</p></div>
@if(auth()->user()->isSupervisor())<a class="btn primary" href="/tasks/create"><span style="font-size:19px">＋</span> Assign task</a>
@endif</div>
<div class="stats">
@foreach($counts as $label=>$count)<a href="/tasks{{ $label==='Overdue'?'?overdue=1':($label==='Awaiting review'?'?status=submitted':($label==='Approved'?'?status=approved':'')) }}" class="card {{ $label==='Overdue'?'stat-warn':'' }}"><div class="stat-accent"></div><span class="muted">{{ $label==='Open'?'Open tasks':$label }}</span><div class="stat-number">{{ str_pad($count,2,'0',STR_PAD_LEFT) }}</div><span class="stat-sub">{{ ['Open'=>'Assigned & in progress','Overdue'=>'Needs a little attention','Awaiting review'=>'Proof ready to check','Approved'=>'Work signed off'][$label] }}</span></a>
@endforeach</div>
<div class="hero"><div><p class="eyebrow">KEEP THE SHIFT MOVING</p><h2>{{ $counts['Overdue']?'A few tasks need your attention.':'Good work starts with a clear plan.' }}</h2><p>{{ $counts['Overdue']?'Check the overdue work, review blockers, and help your team move forward.':'Keep track of deadlines and make sure everyone knows their next step.' }}</p></div><a class="btn" href="/tasks{{ $counts['Overdue']?'?overdue=1':'' }}">View tasks →</a></div>
<div class="grid-two"><section class="card"><div class="card-head"><div><h2>Work in focus</h2><p class="field-help">Open tasks, earliest deadline first</p></div><a class="muted" href="/tasks">View all →</a></div>
@include('partials.task-table')</section><div class="stack"><section class="card"><div class="card-head"><h2>Today's checklist</h2><span class="badge">{{ $todayTasks->count() }} tasks</span></div>
<div class="today-list">
@forelse($todayTasks as $task)<div class="today-item"><a class="task-name" href="/tasks/{{ $task->id }}">{{ $task->title }}</a><span class="subline">{{ \App\Support\BusinessTime::show($task->due_at,'H:i') }} · {{ $task->area }}</span><div style="margin-top:7px"><span class="badge {{ $task->status }}">{{ \App\Models\Task::STATUSES[$task->status] }}</span></div></div>
@empty<div class="empty">Nothing due today.<br>Check your upcoming tasks.</div>
@endforelse
</div></section><section class="card"><h2>Upcoming tasks</h2>
@forelse($upcomingTasks as $task)
<div class="today-item"><a class="task-name" href="/tasks/{{ $task->id }}">{{ $task->title }}</a><span class="subline">{{ \App\Support\BusinessTime::show($task->due_at,'d M, H:i') }} · {{ $task->area }}</span></div>
@empty
<div class="empty">No upcoming tasks yet.</div>
@endforelse
</section><section class="card" style="background:#edf1e6"><p class="eyebrow">LOOK AHEAD</p><h2>Everything has its time.</h2><p class="muted" style="margin:12px 0;line-height:1.8">Plan the day, see the week, and keep deadlines in view.</p><a href="/calendar" class="btn">Open calendar →</a></section></div></div>
@endsection
