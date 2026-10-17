<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Company policies · TaskSure</title>@include('partials.brand-icons')@vite(['resources/css/app.css','resources/js/app.js'])</head><body>
<main class="policy-gate"><section class="card policy-dialog" role="dialog" aria-modal="true" aria-labelledby="policy-library-title">
<div class="card-head"><a class="brand" href="/company-policy"><img class="brandmark" src="{{ asset('tasksure-icon.svg') }}" alt="" width="35" height="35">TaskSure</a><form method="post" action="/logout">@csrf @include('partials.store-context')<button class="btn" type="submit">Sign out</button></form></div>
@include('partials.messages')
@if(session('policy_changed'))<p class="flash errors" role="alert">{{ session('policy_changed') }}</p>@endif
<p class="eyebrow" style="margin-top:24px">COMPANY POLICY LIBRARY</p><h1 id="policy-library-title">Company policies</h1>
@if($state['required'])<p class="notice" style="margin-top:16px">{{ $state['outstanding_count'] }} {{ $state['outstanding_count'] === 1 ? 'policy requires' : 'policies require' }} your acknowledgement. Read and confirm every outstanding policy before continuing.</p>
@else<a class="btn primary" href="/" style="margin-top:18px">Continue to TaskSure</a>@endif
<nav aria-label="Company policies" class="stack" style="margin:20px 0">
@forelse($state['policies'] as $item)
<a class="today-item" href="/company-policy?policy_id={{ $item['policy_id'] }}" @if($policy && $item['policy_id']===$policy['policy_id']) aria-current="page" @endif><strong>{{ $item['title'] }}</strong><p class="field-help">Version {{ $item['version'] }} · Published {{ \App\Support\BusinessTime::show($item['published_at']) }} · SAST · {{ $item['required'] ? 'Outstanding' : ($item['acknowledged_at'] ? 'Acknowledged' : 'Read policy') }}</p></a>
@empty<p style="margin-top:16px">No company policy has been published.</p>@endforelse
</nav>
@if($policy)
<section aria-labelledby="policy-title"><h2 id="policy-title">{{ $policy['title'] }}</h2><p class="field-help">Version {{ $policy['version'] }} · Published {{ \App\Support\BusinessTime::show($policy['published_at']) }} · SAST</p>
@if(isset($policy['document']))
@include('policies.pdf', ['pdfName'=>$policy['document']['name'], 'pdfSize'=>$policy['document']['size'], 'pdfTitle'=>$policy['title'], 'pdfUrl'=>'/company-policy/versions/'.$policy['id'].'/pdf'])
@else<div class="policy-content note" tabindex="0" role="region" aria-label="Full company policy">{{ $policy['content'] }}</div>@endif
@if($required)<form method="post" action="/company-policy/acknowledge" data-policy-ack>@csrf @include('partials.store-context')<input type="hidden" name="policy_version_id" value="{{ $policy['id'] }}"><label class="check"><input id="policy-acknowledged" type="checkbox" name="acknowledged" value="1" required>I have read and understand this company policy.</label><button id="policy-confirm" class="btn primary" type="submit" disabled style="margin-top:20px">Confirm and continue</button></form>
@elseif($policy['acknowledged_at'])<p class="field-help">Acknowledged {{ \App\Support\BusinessTime::show($policy['acknowledged_at']) }} · SAST</p>@endif
</section>
@endif
</section></main></body></html>
