
@extends('layout')
@section('title','Calendar')
@section('content')<div class="pagehead"><div><p class="eyebrow">A LITTLE PERSPECTIVE</p><h1>The week ahead</h1><p class="muted" style="margin-top:9px">View task deadlines in South African time. Click a task to open its details.</p></div>
@if(auth()->user()->isSupervisor())<a class="btn primary" href="/tasks/create">＋ Assign task</a>
@endif</div><section class="card"><form id="calendar-filters">
@include('partials.filters')</form><p class="field-help" style="margin-bottom:18px">{{ auth()->user()->isSupervisor()?'Click a date to assign work. Drag a task to propose a new deadline; a reason and confirmation are required.':'Your calendar shows your own assigned work.' }}</p><div id="calendar" data-supervisor="{{ auth()->user()->isSupervisor()?1:0 }}"></div><p id="calendar-message" class="errors" role="alert"></p></section><dialog id="reschedule-dialog"><h2>Confirm new deadline</h2><p id="reschedule-title" style="margin:14px 0"></p><form id="reschedule-form"><label>Deadline · SAST</label><input type="datetime-local" name="due_at" required><label style="margin-top:14px">Reason for changing the deadline</label><textarea name="reason" required></textarea><label class="check" style="margin-top:14px"><input type="checkbox" name="confirmed" value="1" required>I confirm this change.</label><p id="reschedule-error" role="alert"></p><div class="form-actions"><button class="btn primary">Save deadline</button><button type="button" class="btn" id="reschedule-cancel">Cancel</button></div></form></dialog>
@endsection
