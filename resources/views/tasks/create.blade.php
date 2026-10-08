
@extends('layout')
@section('title','Assign task')
@section('content')<div class="pagehead"><div><p class="eyebrow">GIVE THE WORK DIRECTION</p><h1>Assign a task</h1><p class="muted" style="margin-top:9px">One task. One owner. A clear deadline.</p></div><a href="/tasks" class="btn">Back to tasks</a></div><form method="post" action="/tasks" class="card" style="max-width:850px">
@csrf
@include('partials.task-fields')<div class="form-grid" style="margin-top:22px"><div><label>Scheduled start · SAST (optional)</label><input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}"></div><div><label>Deadline · SAST</label><input type="datetime-local" name="due_at" required value="{{ old('due_at',request('date')?request('date').'T17:00':null) }}"></div></div><div class="form-actions"><button class="btn primary">Assign task →</button><a class="muted" href="/tasks">Cancel</a></div></form>
@endsection
