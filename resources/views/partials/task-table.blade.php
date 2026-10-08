<div class="table-wrap"><table><thead><tr><th>Task / store area</th>
@if(auth()->user()->isSupervisor())<th>Responsible</th>
@endif<th>Deadline · SAST</th><th>Status</th><th>Priority</th></tr></thead><tbody>
@forelse($tasks as $task)<tr><td><a class="task-name" href="/tasks/{{ $task->id }}">{{ $task->title }}</a><span class="subline">{{ $task->category->name }} · {{ $task->area }}</span></td>
@if(auth()->user()->isSupervisor())<td>{{ $task->employee->name }}</td>
@endif<td>{{ \App\Support\BusinessTime::show($task->due_at,'d M, H:i') }}
@if($task->overdue())<br><span class="badge overdue" style="margin-top:5px">Overdue</span>
@endif</td><td><span class="badge {{ $task->status }}">{{ \App\Models\Task::STATUSES[$task->status] }}</span></td><td><span class="priority {{ $task->priority }}">{{ $task->priority }}</span></td></tr>
@empty<tr><td colspan="5"><div class="empty">All clear here. No tasks match this view.</div></td></tr>
@endforelse</tbody></table></div>
