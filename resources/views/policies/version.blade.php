@extends('layout')
@section('title','Published company policy')
@section('content')
<div class="pagehead"><div><p class="eyebrow">IMMUTABLE PUBLISHED VERSION {{ $policy->version }}</p><h1>{{ $policy->title }}</h1><p class="field-help">Published {{ \App\Support\BusinessTime::show($policy->published_at) }} · SAST · {{ $policy->publisher->name }}</p></div><a class="btn" href="/admin/company-policy">Company policy</a></div><section class="card"><div class="note">{{ $policy->content }}</div></section>
@endsection
