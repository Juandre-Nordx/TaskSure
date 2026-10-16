
@extends('layout')
@section('title','Task details')
@section('content')
<div class="pagehead"><div><p class="eyebrow">TASK #{{ str_pad($task->id,4,'0',STR_PAD_LEFT) }} · {{ $task->category->name }}</p><h1>{{ $task->title }}</h1><div style="display:flex;gap:12px;margin-top:14px"><span class="badge {{ $task->status }}">{{ \App\Models\Task::STATUSES[$task->status] }}</span>
@if($task->overdue())<span class="badge overdue">Overdue</span>
@endif<span class="badge">{{ ucfirst($task->priority) }} priority</span></div></div><a class="btn" href="/tasks">← Tasks</a></div>
<div class="grid-two"><div class="stack"><section class="card"><h2>The assignment</h2><p class="note" style="margin:20px 0;line-height:1.9">{{ $task->instructions }}</p><div class="form-grid"><div><p class="eyebrow">RESPONSIBLE</p><strong>{{ $task->employee->name }} · {{ ucfirst($task->employee->role) }}</strong></div><div><p class="eyebrow">STORE AREA</p><strong>{{ $task->area }}</strong></div><div><p class="eyebrow">DEADLINE · SAST</p><strong>{{ \App\Support\BusinessTime::show($task->due_at) }}</strong></div><div><p class="eyebrow">SCHEDULED START</p><strong>{{ \App\Support\BusinessTime::show($task->scheduled_at) }}</strong></div><div><p class="eyebrow">ASSIGNED BY</p>{{ $task->creator->name }}<p class="field-help">{{ \App\Support\BusinessTime::show($task->created_at) }}</p></div><div><p class="eyebrow">PROOF REQUIRED</p>{{ implode(' + ',array_map('ucfirst',$task->required_evidence)) }}</div></div>
@if($task->cancellation_reason)<p class="flash errors" style="margin-top:20px">Cancelled: {{ $task->cancellation_reason }}</p>
@endif</section>
@can('work',$task)
@if(in_array($task->status,['assigned','in_progress']))<section class="card"><div class="card-head"><h2>Your next step</h2><span class="badge">Proof of work</span></div>
@if($task->status!=='in_progress')<form method="post" action="/tasks/{{ $task->id }}/action" style="margin-bottom:20px">
@csrf @include('partials.store-context')<input type="hidden" name="action" value="start"><button class="btn">Start work →</button></form>
@endif
<form data-upload method="post" action="/tasks/{{ $task->id }}/uploads" enctype="multipart/form-data">
@csrf @include('partials.store-context')<label for="file">Add a photo or PDF document</label><input id="file" type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><p class="field-help">JPEG, PNG, WebP or PDF · up to {{ round(config('tasksure.upload_max_kb')/1024,1) }} MB. Each completion attempt needs its own proof.</p><button class="btn" style="margin:12px 0">Upload evidence ↑</button><progress class="progress" hidden value="0" max="100"></progress><p data-upload-status role="status"></p></form>
<div class="evidence">
@foreach($task->attachments->whereNull('submission_id')->where('user_id',auth()->id()) as $a)<a href="/attachments/{{ $a->id }}">
@if($a->kind==='photo')<img src="/attachments/{{ $a->id }}?preview=1" alt="{{ $a->original_name }}">
@endif<span class="subline">{{ $a->original_name }}</span></a>
@endforeach</div>
<form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:20px">
@csrf @include('partials.store-context')<input type="hidden" name="action" value="complete"><label for="note">Completion note {{ in_array('note',$task->required_evidence)?'(required)':'(optional)' }}</label><textarea id="note" name="note"
@required(in_array('note',$task->required_evidence)) placeholder="Describe what you completed and anything the reviewer should know.">{{ old('note') }}</textarea><button class="btn primary" style="margin-top:14px">Complete task ✓</button></form></section>
@endif
@endcan
<section class="card"><h2>Completion history</h2><p class="field-help">Every attempt keeps its own evidence and applicable deadline.</p>
@forelse($task->submissions as $s)<div style="margin-top:22px;padding-top:20px;border-top:1px solid var(--line)"><div class="card-head"><h3>Attempt #{{ $loop->iteration }}</h3><span class="badge">{{ $s->reviewLabel() }}</span></div><p>Completed {{ \App\Support\BusinessTime::show($s->submitted_at) }} · <strong>{{ $s->submitted_at->lte($s->due_at)?'On time':'Late' }}</strong></p><p class="field-help">Deadline for this attempt: {{ \App\Support\BusinessTime::show($s->due_at) }}</p><p class="note" style="margin-top:12px">{{ $s->note }}</p><div class="evidence">
@foreach($s->attachments as $a)<a href="/attachments/{{ $a->id }}">
@if($a->kind==='photo')<img src="/attachments/{{ $a->id }}?preview=1" alt="{{ $a->original_name }}"><br>
@endif<span class="subline">Download {{ $a->original_name }}</span></a>
@endforeach</div>
@if($s->reviewed_at)<p style="margin-top:14px"><strong>{{ $s->reviewer?->name }}</strong> · {{ \App\Support\BusinessTime::show($s->reviewed_at) }}</p><p class="note" style="margin-top:7px">{{ $s->review_reason }}</p>
@endif</div>
@empty<div class="empty">No completions yet. Proof will appear here after the task is completed.</div>
@endforelse</section>
<section class="card"><h2>Completion and review</h2><p>Started: {{ \App\Support\BusinessTime::show($task->started_at) }}</p><p>Completed: {{ \App\Support\BusinessTime::show($task->completed_at) }} · {{ $task->completer?->name ?? 'Not recorded' }}</p>
@if($task->status==='completed')<p><strong>{{ $task->review_status==='reviewed'?'Reviewed':'Not reviewed' }}</strong> · {{ $task->reviewer?->name ?? 'Reviewer not recorded' }} · {{ \App\Support\BusinessTime::show($task->reviewed_at) }}</p><p class="field-help">Review is optional. This task is already Completed.</p>
@can('review',$task)<form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:16px">@csrf @include('partials.store-context')<label for="review-note">Review note (optional)</label><textarea id="review-note" name="reason" maxlength="5000"></textarea>
@if($task->review_status!=='reviewed')<button class="btn primary" name="action" value="mark_reviewed">Mark as reviewed</button>@endif</form>
<form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:16px">@csrf @include('partials.store-context')<input type="hidden" name="action" value="reopen"><label for="correction-note">Correction note (required to reopen)</label><textarea id="correction-note" name="reason" required maxlength="5000" placeholder="Explain what needs fixing."></textarea><button class="btn danger" style="margin-top:12px">Reopen task</button></form>@endcan
@endif
<h3 style="margin-top:20px">Review history</h3>
@forelse($task->reviews as $review)<div class="today-item"><strong>{{ $review->action==='legacy_approval'?'Historical approval':ucfirst($review->action) }}</strong><p>{{ $review->reviewer?->name ?? 'Unknown historical reviewer' }} · {{ \App\Support\BusinessTime::show($review->occurred_at) }}</p><p class="note">{{ $review->note }}</p><p class="field-help">Completion #{{ $review->submission_id ?? 'Not recorded' }}</p></div>
@empty<p class="field-help">No review events recorded.</p>@endforelse</section>
<section class="card"><h2>Comments & blockers</h2><form method="post" action="/tasks/{{ $task->id }}/action" style="margin-top:16px">
@csrf @include('partials.store-context')<label for="body">Share an update</label><textarea id="body" name="body" required placeholder="Add context, ask a question, or flag something holding you up."></textarea><div class="form-actions"><button class="btn" name="action" value="comment">Add comment</button>
@can('work',$task)<button class="btn danger" name="action" value="blocker">Report a blocker</button>
@endcan</div><p class="field-help">Reporting a blocker notifies reviewers. The deadline stays in place.</p></form></section></div>
<div class="stack">
@include('partials.task-recurrence')
@can('manage',$task)
@if(!in_array($task->status,['completed','cancelled']))<section class="card"><h2>Manage assignment</h2><details><summary>Change deadline</summary><form method="post" action="/tasks/{{ $task->id }}/action">
@csrf @include('partials.store-context')<input type="hidden" name="action" value="reschedule"><label>New deadline · SAST</label><input type="datetime-local" name="due_at" required value="{{ \App\Support\BusinessTime::show($task->due_at,'Y-m-d\TH:i') }}"><label style="margin-top:12px">Reason for change</label><textarea name="reason" required></textarea><label class="check" style="margin-top:12px"><input type="checkbox" name="confirmed" value="1" required>I confirm this deadline change.</label><button class="btn primary">Save new deadline</button></form></details>
<details><summary>Reassign task</summary><form method="post" action="/tasks/{{ $task->id }}/action">
@csrf @include('partials.store-context')<input type="hidden" name="action" value="reassign"><label>New responsible person</label><select name="employee_id">
@foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->name }} · {{ ucfirst($e->role) }}</option>
@endforeach</select><label style="margin-top:12px">Reason</label><textarea name="reason" required></textarea><button class="btn" style="margin-top:12px">Reassign task</button></form></details>
<details><summary>Cancel task</summary><form method="post" action="/tasks/{{ $task->id }}/action">
@csrf @include('partials.store-context')<input type="hidden" name="action" value="cancel"><label>Cancellation reason</label><textarea name="reason" required></textarea><button class="btn danger" style="margin-top:12px">Cancel task</button></form></details></section>
@endif
@endcan
<section class="card"><h2>Activity record</h2><div class="timeline" style="margin-top:23px">
@foreach($task->activities as $a)<div class="timeline-item"><strong>{{ ucfirst(str_replace('_',' ',$a->type)) }}</strong><p class="note">{{ $a->body }}</p><span class="subline">{{ $a->user?->name??'System' }} · {{ \App\Support\BusinessTime::show($a->created_at) }}</span></div>
@endforeach</div></section><section class="card"><h2>Deadline history</h2>
@forelse($task->deadlineChanges as $d)<div class="today-item"><p>{{ \App\Support\BusinessTime::show($d->old_due_at) }} →<br><strong>{{ \App\Support\BusinessTime::show($d->new_due_at) }}</strong></p><p class="note" style="margin-top:8px">{{ $d->reason }}</p><p class="subline" style="margin-top:8px">{{ $d->user->name }} · {{ \App\Support\BusinessTime::show($d->created_at) }}</p></div>
@empty<p class="field-help" style="margin-top:14px">The original deadline is unchanged.</p>
@endforelse</section></div></div>
@endsection
