
@extends('layout')
@section('title','Notifications')
@section('content')<div class="pagehead"><div><p class="eyebrow">STAY IN THE LOOP</p><h1>Your notifications</h1><p class="muted" style="margin-top:9px">Assignments, deadlines, and updates that matter to you.</p></div></div><section class="card">
@forelse($alerts as $a)<div class="alert-row"><span class="avatar">{{ $a->read_at?'✓':'•' }}</span><div style="flex:1"><a href="{{ $a->task_id?'/tasks/'.$a->task_id:'/notifications' }}"><strong>{{ $a->message }}</strong></a><p class="field-help">{{ \App\Support\BusinessTime::show($a->created_at) }} · {{ str_replace('_',' ',$a->type) }}</p>
@if(auth()->user()->role==='admin')<p class="field-help">Email: {{ $a->email_status }} {{ $a->delivery_error }}</p>
@endif</div>
@if(!$a->read_at)<form method="post" action="/notifications/{{ $a->id }}/read">
@csrf<button class="btn">Mark read</button></form>
@endif</div>
@empty<div class="empty">You're all caught up. Updates will appear here.</div>
@endforelse<div style="margin-top:20px">{{ $alerts->links() }}</div></section>
@endsection
