<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>
@yield('title', 'Overview') · TaskSure</title>
@vite(['resources/css/app.css','resources/js/app.js'])</head><body>
<div class="shell"><aside class="sidebar"><a class="brand" href="/"><span class="brandmark">✓</span>TaskSure<span style="color:#a8cf87">.</span></a><div><p class="navlabel">STORE OPERATIONS</p><nav>
@foreach(['/' => ['◫','Overview'], '/tasks' => ['☷','Tasks'], '/calendar'=>['▦','Calendar'], '/reports'=>['↗','Reports'], '/notifications'=>['♧','Notifications']] as $url=>$item)<a class="navlink {{ request()->getPathInfo()===$url?'current':'' }}" href="{{ $url }}"><span class="navicon">{{ $item[0] }}</span>{{ $item[1] }}</a>
@endforeach
@if(auth()->user()->role==='admin')<a class="navlink {{ request()->is('admin/users')?'current':'' }}" href="/admin/users">People</a><a class="navlink {{ request()->is('admin/settings')?'current':'' }}" href="/admin/settings">Store settings</a>
@endif</nav></div><div class="sidebar-foot"><p class="eyebrow" style="color:#b0c99c">A little clarity. A better shift.</p><p style="font-size:12px;color:#9eb7a7;line-height:1.8">Every task has an owner.<br>Every effort leaves a record.</p></div></aside>
<div class="main"><header class="topbar"><span class="muted">Store workspace <span style="margin:0 12px;color:#c4cec1">/</span> <strong style="color:#284c3b">
@yield('title','Overview')</strong></span><div class="small-links"><a href="/notifications" aria-label="Notifications">Notifications 
@php($unread=\App\Models\Alert::where('user_id',auth()->id())->whereNull('read_at')->count())
@if($unread)<span class="badge">{{ $unread }}</span>
@endif</a><span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="desktop-only"><strong>{{ auth()->user()->name }}</strong><br><span class="subline">{{ ucfirst(auth()->user()->role) }}</span></span><form method="post" action="/logout">
@csrf<button class="muted" style="font-size:12px">Sign out</button></form></div></header><main class="content">
@include('partials.messages')
@yield('content')</main></div></div>
@stack('scripts')</body></html>
