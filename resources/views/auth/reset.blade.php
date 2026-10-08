
@extends('auth.layout')
@section('content')<h1>Choose a new password.</h1><form method="post" action="/reset-password">
@csrf<input type="hidden" name="token" value="{{ $token }}"><div><label>Email</label><input type="email" name="email" value="{{ $email }}" required></div><div><label>New password</label><input type="password" name="password" required autocomplete="new-password"><p class="field-help">At least 12 characters, upper and lower case, and a number.</p></div><div><label>Confirm password</label><input type="password" name="password_confirmation" required></div><button class="btn primary">Reset password</button></form>
@endsection
