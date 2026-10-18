@extends('layout')
@section('title','Edit specific dates')
@section('content')
<div class="pagehead"><div><h1>Edit specific dates</h1><p class="muted">{{ $template->title }} · {{ $template->employee->name }}</p></div><a class="btn" href="/recurring-tasks">Recurring tasks</a></div>
<form class="card" method="post" action="/recurring-tasks/{{ $template->id }}/schedule" data-date-form style="max-width:850px">@csrf @include('partials.store-context')
<input name="schedule_revision" type="hidden" value="{{ $template->schedule_revision }}">
@include('partials.specific-dates',['dates'=>$template->specific_dates,'dueTime'=>$template->due_time])
<label>Schedule status<select name="active"><option value="1" @selected($template->active || $template->generationBlock()==='Finished')>Active</option><option value="0" @selected(!$template->active && $template->generationBlock()!=='Finished')>Paused</option></select></label>
<p class="field-help">Date and deadline changes apply to future, unstarted work. Past, started, completed, cancelled or previously worked tasks and their proof remain unchanged. Removing a future unstarted date cancels its task and preserves its record; adding it again restores that same task. Other cancelled tasks stay cancelled.</p><button class="btn primary" type="submit" style="margin-top:16px">Save schedule</button></form>
@endsection
