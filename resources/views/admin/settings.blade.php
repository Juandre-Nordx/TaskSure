
@extends('layout')
@section('title','Store settings')
@section('content')<div class="pagehead"><div><p class="eyebrow">MAKE THE ROUTINE EASIER</p><h1>Store settings</h1><p class="muted" style="margin-top:9px">Categories, reminders, and recurring duties.</p></div></div><div class="grid-two"><section class="card"><h2>Recurring task templates</h2>
@foreach($templates as $template)<details style="border-bottom:1px solid var(--line);padding:10px 0"><summary>{{ $template->title }} <span class="badge">{{ $template->frequency }} · {{ $template->active?'Active':'Paused' }}</span></summary><p class="field-help" style="margin-bottom:15px">{{ $template->employee->name }}</p>
@include('admin.template-form')</details>
@endforeach<details open style="margin-top:15px"><summary>＋ New recurring duty</summary>
@include('admin.template-form',['template'=>null])</details></section><div class="stack"><section class="card"><h2>Task categories</h2><div style="display:flex;gap:8px;flex-wrap:wrap;margin:20px 0">
@foreach($categories as $c)<span class="badge">{{ $c->name }}</span>
@endforeach</div><form method="post" action="/admin/categories">
@csrf<label>New category</label><input name="name" required maxlength="80"><button class="btn" style="margin-top:12px">Add category</button></form></section><section class="card"><h2>Deadline reminders</h2><form method="post" action="/admin/reminders">
@csrf<label style="margin-top:16px">Notify before deadline (minutes)</label><input name="reminder_minutes" type="number" min="1" max="10080" value="{{ $reminder }}" required><p class="field-help">One upcoming and one overdue notification per recipient, task, and deadline. Changing the deadline creates a fresh reminder cycle.</p><button class="btn primary" style="margin-top:12px">Save reminder interval</button></form></section><section class="card"><h2>External delivery</h2><p class="muted" style="margin-top:12px;line-height:1.8">In-app notifications are always available. Email: {{ config('tasksure.email_enabled')?'enabled; inspect delivery status in notifications':'disabled' }}. WhatsApp is unconfigured. Your deployment administrator must configure external providers.</p></section></div></div>
@endsection
