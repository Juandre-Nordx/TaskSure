<form method="post" action="/admin/templates{{ isset($template)?'/'.$template->id:'' }}">
@csrf
@include('partials.task-fields')<div class="form-grid" style="margin-top:20px"><div><label>Repeat</label><select name="frequency">
@foreach(['daily','weekly','monthly'] as $v)<option 
@selected(($template->frequency??'daily')===$v)>{{ $v }}</option>
@endforeach</select></div><div><label>Next occurrence date · SAST</label><input type="date" name="next_date" required value="{{ isset($template)?$template->next_date->format('Y-m-d'):now()->timezone('Africa/Johannesburg')->format('Y-m-d') }}"></div><div><label>Due time · SAST</label><input type="time" name="due_time" required value="{{ $template->due_time??'17:00' }}"></div><div><label>Template status</label><select name="active"><option value="1" 
@selected($template->active??true)>Active</option><option value="0" 
@selected(isset($template)&&!$template->active)>Paused</option></select></div></div><button class="btn primary" style="margin-top:20px">Save template</button><p class="field-help">A separate task is generated on each occurrence date. Future instances use the current template; existing instances are unchanged. Monthly tasks use the selected day, clamped to the last day of shorter months.</p></form>
