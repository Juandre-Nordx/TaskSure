@extends('auth.layout')
@section('title','Store unavailable')
@section('content')<h1>Store unavailable</h1><p class="muted" style="margin:20px 0">{{ $message }}</p><a class="btn primary" href="/stores">Choose a store</a><form method="post" action="/logout">@csrf @include('partials.store-context')<button class="btn">Sign out</button></form>@endsection
