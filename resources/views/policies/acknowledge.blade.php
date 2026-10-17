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
@if(!$state['selected_is_current'])<p class="notice" style="margin:16px 0">Previous version — read only. This does not replace the current policy or its acknowledgement.</p><a class="btn" href="/company-policy?policy_id={{ $policy['policy_id'] }}">Read current version (version {{ $state['current_policy']['version'] }})</a>@endif
@if(isset($policy['document']))
@include('policies.pdf', ['pdfName'=>$policy['document']['name'], 'pdfSize'=>$policy['document']['size'], 'pdfTitle'=>$policy['title'], 'pdfUrl'=>'/company-policy/versions/'.$policy['id'].'/pdf'])
@else<div class="policy-content note" tabindex="0" role="region" aria-label="Full company policy">{{ $policy['content'] }}</div>@endif
@if($required)<form method="post" action="/company-policy/acknowledge" data-policy-ack>@csrf @include('partials.store-context')<input type="hidden" name="policy_version_id" value="{{ $policy['id'] }}"><label class="check"><input id="policy-acknowledged" type="checkbox" name="acknowledged" value="1" required>I have read and understand this company policy.</label><button id="policy-confirm" class="btn primary" type="submit" disabled style="margin-top:20px">Confirm and continue</button></form>
@elseif($policy['acknowledged_at'])<p class="field-help">Acknowledged {{ \App\Support\BusinessTime::show($policy['acknowledged_at']) }} · SAST</p>@endif
</section>
<section aria-labelledby="policy-history-title" style="margin-top:24px"><h2 id="policy-history-title">Previous published versions</h2><p class="field-help">Read earlier versions of this policy. Only your own recorded acknowledgements are shown.</p>
<nav aria-label="Previous policy versions" class="stack" style="margin:16px 0">
@forelse($state['history']['data'] as $version)<a class="today-item" href="/company-policy?policy_id={{ $policy['policy_id'] }}&amp;version_id={{ $version['id'] }}&amp;history_page={{ $state['history']['meta']['page'] }}" @if($version['id']===$policy['id']) aria-current="page" @endif><strong>Version {{ $version['version'] }} · {{ $version['title'] }}</strong><p class="field-help">Published {{ \App\Support\BusinessTime::show($version['published_at']) }} · SAST</p><p class="field-help">{{ $version['acknowledged_at'] ? 'Acknowledged '.\App\Support\BusinessTime::show($version['acknowledged_at']).' · SAST' : 'Not acknowledged for this version' }}</p></a>@empty<p class="field-help">No previous published versions.</p>@endforelse
</nav>
@if($state['history']['meta']['last_page']>1)<nav aria-label="Policy history pages"><p class="field-help">Page {{ $state['history']['meta']['page'] }} of {{ $state['history']['meta']['last_page'] }}</p>@foreach(['Previous'=>-1,'Next'=>1] as $label=>$offset)@php($page=$state['history']['meta']['page']+$offset)@if($page>=1 && $page<=$state['history']['meta']['last_page'])<a class="btn" href="/company-policy?{{ http_build_query(['policy_id'=>$policy['policy_id'],'version_id'=>$policy['id'],'history_page'=>$page]) }}">{{ $label }}</a>@endif @endforeach</nav>@endif
</section>
@endif
</section></main></body></html>
