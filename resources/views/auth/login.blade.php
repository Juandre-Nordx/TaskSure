
@extends('auth.layout')
@section('content')<p class="eyebrow">WELCOME BACK</p><h1>Let's get to work.</h1><p class="muted" style="margin-top:10px">Sign in to your store workspace.</p><form method="post" action="/login">
@csrf<div><label for="email">Email address</label><input id="email" type="email" name="email" required autocomplete="username" value="{{ old('email') }}"></div><div><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div><div style="display:flex;justify-content:space-between"><label class="check"><input type="checkbox" name="remember" value="1">Remember me</label><a href="/forgot-password" class="muted">Forgot password?</a></div><button class="btn primary">Sign in →</button></form><p class="field-help" style="margin-top:25px">Need an account? Ask your store administrator.</p>
@endsection
