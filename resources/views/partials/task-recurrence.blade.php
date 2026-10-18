<section class="card"><h2>Recurring task</h2>
@if($task->template)
@php($plan=$task->template)
<p style="margin-top:15px"><strong>{{ \App\Models\TaskTemplate::FREQUENCIES[$plan->frequency] }}</strong> · {{ $plan->generationBlock() ?? 'Active' }}</p><p class="field-help">Next occurrence date: {{ $plan->next_date->format('d M Y') }} · Due {{ $plan->due_time }} SAST. {{ $plan->ends_on?'Repeats through '.$plan->ends_on->format('d M Y').'.':'Repeats until paused.' }}</p><p class="field-help">Each occurrence keeps its own proof and history. Assignment or deadline changes on this task apply to this occurrence only.</p>
@if($plan->frequency==='specific_dates' && $plan->manageableBy(auth()->user()))<a class="btn" style="margin-top:14px" href="/recurring-tasks/{{ $plan->id }}/edit">Edit specific dates</a><p class="field-help">{{ count($plan->specific_dates) }} selected dates: {{ implode(', ',$plan->specific_dates) }}</p>@endif
@if($plan->manageableBy(auth()->user()) && $plan->generationBlock()!=='Finished')<form method="post" action="/recurring-tasks/{{ $plan->id }}/status">@csrf @include('partials.store-context')<input type="hidden" name="active" value="{{ $plan->active?'0':'1' }}"><button class="btn" style="margin-top:14px">{{ $plan->active?'Pause series':'Resume series' }}</button><p class="field-help">Pausing stops future generation. Resuming catches up missed occurrences.</p></form>@endif
@else
<p class="field-help">This task does not repeat.</p>
@can('manage',$task)
@if($task->employee->active)
@php($nextRepeat=max(\App\Support\BusinessTime::show($task->due_at->copy()->addDay(),'Y-m-d'),now()->timezone(config('tasksure.timezone'))->addDay()->toDateString()))
<form method="post" action="/tasks/{{ $task->id }}/recurrence" style="margin-top:16px">@csrf @include('partials.store-context')<label for="repeat-frequency">Make this task recurring</label><select id="repeat-frequency" name="frequency">@foreach(\App\Models\TaskTemplate::FREQUENCIES as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><div data-interval><label for="first-repeat" style="margin-top:12px">First repeat date · SAST</label><input id="first-repeat" type="date" name="next_date" required value="{{ old('next_date',$nextRepeat) }}"><label for="repeat-end" style="margin-top:12px">Repeat until · SAST (optional)</label><input id="repeat-end" type="date" name="ends_on" value="{{ old('ends_on') }}"></div>@include('partials.specific-dates',['minimum'=>max(\App\Support\BusinessTime::show($task->due_at->copy()->addDay(),'Y-m-d'),now()->timezone(config('tasksure.timezone'))->toDateString()),'dueTime'=>\App\Support\BusinessTime::show($task->due_at,'H:i')])<p class="field-help">Future tasks use this task's instructions, responsible person, required proof and deadline time ({{ \App\Support\BusinessTime::show($task->due_at,'H:i') }} SAST). The current task and its history stay unchanged.</p><button type="submit" class="btn primary" style="margin-top:12px">Make recurring</button></form>
@endif
@endcan
@endif
</section>
