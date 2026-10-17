<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Company policy · TaskSure</title>@include('partials.brand-icons')@vite(['resources/css/app.css','resources/js/app.js'])</head><body>
<main class="policy-gate"><section class="card policy-dialog" role="dialog" aria-modal="true" aria-labelledby="policy-title"><div class="card-head"><a class="brand" href="/company-policy"><img class="brandmark" src="{{ asset('tasksure-icon.svg') }}" alt="" width="35" height="35">TaskSure</a><form method="post" action="/logout">@csrf @include('partials.store-context')<button class="btn" type="submit">Sign out</button></form></div>
@include('partials.messages')
@if(session('policy_changed'))<p class="flash errors" role="alert">{{ session('policy_changed') }}</p>@endif
@if($policy)
<p class="eyebrow" style="margin-top:24px">COMPANY POLICY</p><h1 id="policy-title">{{ $policy->title }}</h1><p class="field-help">Version {{ $policy->version }} · Published {{ \App\Support\BusinessTime::show($policy->published_at) }} · SAST</p>
@if($required)<p style="margin-top:16px">Read the full policy and acknowledge this version to continue using TaskSure.</p>@endif
@if($policy->pdf_path)
@include('policies.pdf', ['pdfName'=>$policy->pdf_name, 'pdfSize'=>$policy->pdf_size, 'pdfTitle'=>$policy->title, 'pdfUrl'=>'/company-policy/versions/'.$policy->id.'/pdf'])
@else
<div class="policy-content note" tabindex="0" role="region" aria-label="Full company policy">{{ $policy->content }}</div>
@endif
@if($required)<form method="post" action="/company-policy/acknowledge" data-policy-ack>@csrf @include('partials.store-context')<input type="hidden" name="policy_version_id" value="{{ $policy->id }}"><label class="check"><input id="policy-acknowledged" type="checkbox" name="acknowledged" value="1" required>I have read and understand this company policy.</label><button id="policy-confirm" class="btn primary" type="submit" disabled style="margin-top:20px">Confirm and continue</button></form>
@else<p class="field-help">{{ $record ? 'Acknowledged '.\App\Support\BusinessTime::show($record->acknowledged_at).' · SAST' : 'Owner access — staff acknowledgement is not required for your account.' }}</p><a class="btn primary" href="/" style="margin-top:18px">Continue to TaskSure</a>@endif
@else<h1 id="policy-title" style="margin-top:24px">Company policy</h1><p style="margin-top:16px">No company policy has been published.</p><a class="btn primary" href="/" style="margin-top:18px">Continue to TaskSure</a>@endif
</section></main></body></html>
