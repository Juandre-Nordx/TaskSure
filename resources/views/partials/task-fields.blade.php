<div class="form-grid"><div class="wide"><label>Task title</label><input name="title" required maxlength="180" value="{{ old('title',$template->title??'') }}" placeholder="e.g. Restock the beer fridges"></div><div class="wide"><label>Detailed instructions</label><textarea name="instructions" required placeholder="What needs to be done? Describe the expected result.">{{ old('instructions',$template->instructions??'') }}</textarea></div><div><label>Responsible employee</label><select name="employee_id" required><option value="">Choose an employee</option>
@foreach($employees as $e)<option value="{{ $e->id }}" 
@selected(old('employee_id',$template->employee_id??null)==$e->id)>{{ $e->name }}</option>
@endforeach</select></div><div><label>Category</label><select name="category_id" required>
@foreach($categories as $c)<option value="{{ $c->id }}" 
@selected(old('category_id',$template->category_id??null)==$c->id)>{{ $c->name }}</option>
@endforeach</select>
@if($categories->isEmpty())<p class="field-help">An administrator needs to add a category in store settings.</p>
@endif</div><div><label>Store area</label><input name="area" required value="{{ old('area',$template->area??'') }}" placeholder="e.g. Beer fridges"></div><div><label>Priority</label><select name="priority">
@foreach(\App\Models\Task::PRIORITIES as $p)<option 
@selected(old('priority',$template->priority??'normal')===$p)>{{ $p }}</option>
@endforeach</select></div><fieldset class="wide"><legend style="font-size:12px;font-weight:600;margin-bottom:8px">Required proof of work</legend><div style="display:flex;gap:22px;flex-wrap:wrap">
@foreach(['photo'=>'Photo','document'=>'PDF document','note'=>'Completion note'] as $k=>$v)<label class="check"><input type="checkbox" name="required_evidence[]" value="{{ $k }}" 
@checked(in_array($k,old('required_evidence',$template->required_evidence??['photo','note'])))>{{ $v }}</label>
@endforeach</div><p class="field-help">Employees must supply every selected type for each submission.</p></fieldset></div>
