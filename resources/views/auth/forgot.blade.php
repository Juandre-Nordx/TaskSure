
@extends('auth.layout')
@section('content')<h1>Reset your password.</h1><p class="muted">We'll send a secure link to your email if delivery is configured.</p><form method="post" action="/forgot-password">
@csrf<div><label>Email address</label><input type="email" name="email" required></div><button class="btn primary">Request reset link</button><a href="/login">Back to sign in</a></form>
@endsection
