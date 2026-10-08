<div class="filter-bar"><div><label for="q">Search</label><input id="q" name="q" placeholder="Task or store area" value="{{ request('q') }}"></div>
@if(auth()->user()->isSupervisor())<div><label for="employee_id">Employee</label><select id="employee_id" name="employee_id"><option value="">All permitted employees</option>
@foreach($employees as $e)<option value="{{ $e->id }}" 
@selected(request('employee_id')==$e->id)>{{ $e->name }}</option>
@endforeach</select></div>
@endif<div><label for="category_id">Category</label><select id="category_id" name="category_id"><option value="">All categories</option>
@foreach($categories as $c)<option value="{{ $c->id }}" 
@selected(request('category_id')==$c->id)>{{ $c->name }}</option>
@endforeach</select></div><div><label for="priority">Priority</label><select id="priority" name="priority"><option value="">All priorities</option>
@foreach(\App\Models\Task::PRIORITIES as $p)<option 
@selected(request('priority')===$p)>{{ $p }}</option>
@endforeach</select></div><div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>
@foreach(\App\Models\Task::STATUSES as $k=>$s)<option value="{{ $k }}" 
@selected(request('status')===$k)>{{ $s }}</option>
@endforeach</select></div><button class="btn primary">Apply filters</button><a class="btn" href="{{ request()->url() }}">Clear</a></div>
