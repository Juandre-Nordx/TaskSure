
@if(session('success'))<div role="status" class="flash">{{ session('success') }}</div>
@endif
@if($errors->any())<div role="alert" class="flash errors"><strong>Please check the following:</strong><ul>
@foreach($errors->all() as $error)<li>{{ $error }}</li>
@endforeach</ul></div>
@endif
