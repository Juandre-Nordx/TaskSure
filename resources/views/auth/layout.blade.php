<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · TaskSure</title>
@include('partials.brand-icons')
@vite(['resources/css/app.css','resources/js/app.js'])</head><body><div class="auth-shell"><section class="auth-intro"><div class="brand"><img class="brandmark" src="{{ asset('tasksure-icon.svg') }}" alt="" width="35" height="35">TaskSure.</div><div><p class="eyebrow" style="color:#b3d19d">BUILT FOR THE EVERYDAY</p><h1>A clear plan.<br>A stronger team.<br>A better store.</h1><p>Keep the floor running smoothly. Assign the work, share the proof, and give every shift a little more direction.</p></div><footer class="muted" style="color:#a7bcaa;font-size:12px">Your store. Working together.</footer></section><main class="auth-form"><div class="auth-box">
@include('partials.messages')
@yield('content')</div></main></div></body></html>
