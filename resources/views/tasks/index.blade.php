
@extends('layout')
@section('title','Tasks')
@section('content')<div class="pagehead"><div><p class="eyebrow">THE WORK, IN ONE PLACE</p><h1>{{ auth()->user()->isSupervisor()?'Team tasks':'My tasks' }}</h1><p class="muted" style="margin-top:9px">{{ $tasks->total() }} tasks in your current view.</p></div>
@if(auth()->user()->isSupervisor())<a class="btn primary" href="/tasks/create">＋ Assign task</a>
@endif</div><section class="card"><form method="get">
@include('partials.filters')<div class="filter-bar"><div><label>Sort by</label><select name="sort">
@foreach(['due_at'=>'Deadline','title'=>'Title','created_at'=>'Assigned date','priority'=>'Priority'] as $k=>$s)<option value="{{ $k }}" 
@selected(request('sort','due_at')===$k)>{{ $s }}</option>
@endforeach</select></div><div><label>Order</label><select name="direction"><option value="asc">Ascending</option><option value="desc" 
@selected(request('direction')==='desc')>Descending</option></select></div><label class="check"><input type="checkbox" name="overdue" value="1" 
@checked(request('overdue'))>Only overdue</label></div></form>
@include('partials.task-table')<div style="margin-top:20px">{{ $tasks->links() }}</div></section>
@endsection
