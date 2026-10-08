
@extends('layout')
@section('title','Task details')
@section('content')
<div class="pagehead"><div><p class="eyebrow">TASK #{{ str_pad($task->id,4,'0',STR_PAD_LEFT) }} · {{ $task->category->name }}</p><h1>{{ $task->title }}</h1><div style="display:flex;gap:12px;margin-top:14px"><span class="badge {{ $task->status }}">{{ \App\Models\Task::STATUSES[$task->status] }}</span>
@if($task->overdue())<span class="badge overdue">Overdue</span>
@endif<span class="badge">{{ ucfirst($task->priority) }} priority</span></div></div><a class="btn" href="/tasks">← Tasks</a></div>
<div class="grid-two"><div class="stack"><section class="card"><h2>The assignment</h2><p class="note" style="margin:20px 0;line-height:1.9">{{ $task->instructions }}</p><div class="form-grid"><div><p class="eyebrow">RESPONSIBLE</p><strong>{{ $task->employee->name }}</strong></div><div><p class="eyebrow">STORE AREA</p><strong>{{ $task->area }}</strong></div><div><p class="eyebrow">DEADLINE · SAST</p><strong>{{ \App\Support\BusinessTime::show($task->due_at) }}</strong></div><div><p class="eyebrow">SCHEDULED START</p><strong>{{ \App\Support\BusinessTime::show($task->scheduled_at) }}</strong></div><div><p class="eyebrow">ASSIGNED BY</p>{{ $task->creator->name }}<p class="field-help">{{ \App\Support\BusinessTime::show($task->created_at) }}</p></div><div><p class="eyebrow">PROOF REQUIRED</p>{{ implode(' + ',array_map('ucfirst',$task->required_evidence)) }}</div></div>
@if($task->cancellation_reason)<p class="flash errors" style="margin-top:20px">Cancelled: {{ $task->cancellation_reason }}</p>
@endif</section>
@can('work',$task)
@if(in_array($task->status,['assigned','in_progress','changes_requested']))<section class="card"><div class="card-head"><h2>Your next step</h2><span class="badge">Proof of work</span></div>
@if($task->status!=='in_progress')<form method="post" action="/tasks/{{ $task->id }}/action" style="margin-bottom:20px">
@csrf<input type="hidden" name="action" value="start"><button class="btn">Start work →</button></form>
@endif
<form data-upload method="post" action="/tasks/{{ $task->id }}/uploads" enctype="multipart/form-data">
@csrf<label for="file">Add a photo or PDF document</label><input id="file" type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><p class="field-help">JPEG, PNG, WebP or PDF · up to {{ round(config('tasksure.upload_max_kb')/1024,1) }} MB. Each submission needs its own proof.</p><button class="btn" style="margin:12px 0">Upload evidence ↑</button><progress class="progress" hidden value="0" max="100"></progress><p data-upload-status role="status"></p></form>
<div class="evidence">
@foreach($task->attachments->whereNull('submission_id')->where('user_id',auth()->id()) as $a)<a href="/attachments/{{ $a->id }}">
@if($a->kind==='photo')<img src="/attachments/{{ $a->id }}?preview=1" alt="{{ $a->original_name }}">
@endif<span class="subline">{{ $a->original_name }}</span></a>
@endforeach</div>
<form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:20px">
@csrf<input type="hidden" name="action" value="submit"><label for="note">Completion note {{ in_array('note',$task->required_evidence)?'(required)':'(optional)' }}</label><textarea id="note" name="note" 
@required(in_array('note',$task->required_evidence)) placeholder="Describe what you completed and anything the reviewer should know.">{{ old('note') }}</textarea><button class="btn primary" style="margin-top:14px">Submit for review →</button></form></section>
@endif
@endcan
<section class="card"><h2>Submission & review history</h2><p class="field-help">Every attempt keeps its own evidence and applicable deadline.</p>
@forelse($task->submissions as $s)<div style="margin-top:22px;padding-top:20px;border-top:1px solid var(--line)"><div class="card-head"><h3>Attempt #{{ $loop->iteration }}</h3><span class="badge">{{ ucfirst(str_replace('_',' ',$s->review_status)) }}</span></div><p>Submitted {{ \App\Support\BusinessTime::show($s->submitted_at) }} · <strong>{{ $s->submitted_at->lte($s->due_at)?'On time':'Late' }}</strong></p><p class="field-help">Deadline for this attempt: {{ \App\Support\BusinessTime::show($s->due_at) }}</p><p class="note" style="margin-top:12px">{{ $s->note }}</p><div class="evidence">
@foreach($s->attachments as $a)<a href="/attachments/{{ $a->id }}">
@if($a->kind==='photo')<img src="/attachments/{{ $a->id }}?preview=1" alt="{{ $a->original_name }}"><br>
@endif<span class="subline">Download {{ $a->original_name }}</span></a>
@endforeach</div>
@if($s->reviewed_at)<p style="margin-top:14px"><strong>{{ $s->reviewer?->name }}</strong> · {{ \App\Support\BusinessTime::show($s->reviewed_at) }}</p><p class="note" style="margin-top:7px">{{ $s->review_reason }}</p>
@endif</div>
@empty<div class="empty">No submissions yet. Proof will appear here after work is submitted.</div>
@endforelse</section>
@can('manage',$task)
@if($task->status==='submitted')<section class="card"><h2>Review this work</h2><form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:16px">
@csrf<label>Review note · required for corrections</label><textarea name="reason" placeholder="Give clear, actionable feedback."></textarea><div class="form-actions"><button class="btn primary" name="action" value="approve">Approve work ✓</button><button class="btn danger" name="action" value="changes_requested">Request corrections</button></div></form></section>
@endif
@endcan
<section class="card"><h2>Comments & blockers</h2><form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:16px">
@csrf<label for="body">Share an update</label><textarea id="body" name="body" required placeholder="Add context, ask a question, or flag something holding you up."></textarea><div class="form-actions"><button class="btn" name="action" value="comment">Add comment</button>
@can('work',$task)<button class="btn danger" name="action" value="blocker">Report a blocker</button>
@endcan</div><p class="field-help">Reporting a blocker notifies reviewers. The deadline stays in place.</p></form></section></div>
<div class="stack">
@can('manage',$task)
@if(!in_array($task->status,['approved','cancelled']))<section class="card"><h2>Manage assignment</h2><details><summary>Change deadline</summary><form method="post" action="/tasks/{{ $task->id }}/action">
@csrf<input type="hidden" name="action" value="reschedule"><label>New deadline · SAST</label><input type="datetime-local" name="due_at" required value="{{ \App\Support\BusinessTime::show($task->due_at,'Y-m-d\TH:i') }}"><label style="margin-top:12px">Reason for change</label><textarea name="reason" required></textarea><label class="check" style="margin-top:12px"><input type="checkbox" name="confirmed" value="1" required>I confirm this deadline change.</label><button class="btn primary">Save new deadline</button></form></details>
@if($task->status!=='submitted')<details><summary>Reassign task</summary><form method="post" action="/tasks/{{ $task->id }}/action">
@csrf<input type="hidden" name="action" value="reassign"><label>New responsible employee</label><select name="employee_id">
@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }}</option>
@endforeach</select><label style="margin-top:12px">Reason</label><textarea name="reason" required></textarea><button class="btn" style="margin-top:12px">Reassign task</button></form></details>
@endif<details><summary>Cancel task</summary><form method="post" action="/tasks/{{ $task->id }}/action">
@csrf<input type="hidden" name="action" value="cancel"><label>Cancellation reason</label><textarea name="reason" required></textarea><button class="btn danger" style="margin-top:12px">Cancel task</button></form></details></section>
@endif
@endcan
<section class="card"><h2>Activity record</h2><div class="timeline" style="margin-top:23px">
@foreach($task->activities as $a)<div class="timeline-item"><strong>{{ ucfirst(str_replace('_',' ',$a->type)) }}</strong><p class="note">{{ $a->body }}</p><span class="subline">{{ $a->user?->name??'System' }} · {{ \App\Support\BusinessTime::show($a->created_at) }}</span></div>
@endforeach</div></section><section class="card"><h2>Deadline history</h2>
@forelse($task->deadlineChanges as $d)<div class="today-item"><p>{{ \App\Support\BusinessTime::show($d->old_due_at) }} →<br><strong>{{ \App\Support\BusinessTime::show($d->new_due_at) }}</strong></p><p class="note" style="margin-top:8px">{{ $d->reason }}</p><p class="subline" style="margin-top:8px">{{ $d->user->name }} · {{ \App\Support\BusinessTime::show($d->created_at) }}</p></div>
@empty<p class="field-help" style="margin-top:14px">The original deadline is unchanged.</p>
@endforelse</section></div></div>
@endsection
