<div class="form-grid" style="margin-top:22px" data-recurrence-options>
<div><label for="recurrence">Repeat</label><select id="recurrence" name="recurrence"><option value="">Does not repeat</option>@foreach(\App\Models\TaskTemplate::FREQUENCIES as $value=>$label)<option value="{{ $value }}" @selected(old('recurrence')===$value)>{{ $label }}</option>@endforeach</select></div>
<div data-recurrence-end><label for="recurrence-until">Repeat until · SAST (optional)</label><input id="recurrence-until" type="date" name="recurrence_until" value="{{ old('recurrence_until') }}"><p class="field-help">Leave empty to repeat until paused.</p></div>
<input type="hidden" name="creation_key" value="{{ old('creation_key',(string) \Illuminate\Support\Str::uuid()) }}">
@include('partials.specific-dates')
<p class="field-help wide" data-recurrence-help>A separate task is created for each occurrence, at the same deadline time in SAST. Each needs its own proof. Monthly tasks keep their original day, using month-end when needed.</p>
</div>
