<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>
@yield('title', 'Overview') · TaskSure</title>
@php($workspace=app(\App\Support\StoreContext::class)->store)
@if($workspace)<meta name="tasksure-store" content="{{ $workspace->id }}">@endif
@include('partials.brand-icons')
@vite(['resources/css/app.css','resources/js/app.js'])</head><body>
<div class="shell"><aside class="sidebar"><a class="brand" href="/"><img class="brandmark" src="{{ asset('tasksure-icon.svg') }}" alt="" width="35" height="35">TaskSure<span style="color:#a8cf87">.</span></a><div><p class="navlabel">STORE OPERATIONS</p><nav>
@if(auth()->user()->role==='super_admin')<a class="navlink" href="/super-admin">Stores</a><a class="navlink" href="/super-admin/stores/create">Create store</a>@else
@foreach(['/' => ['◫','Overview'], '/tasks' => ['☷',auth()->user()->isSupervisor()?'Team tasks':'My tasks'], '/calendar'=>['▦','Calendar'], '/reports'=>['↗','Reports'], '/notifications'=>['♧','Notifications']] as $url=>$item)<a class="navlink {{ request()->getPathInfo()===$url && !($url==='/tasks' && request('scope')==='my')?'current':'' }}" href="{{ $url }}"><span class="navicon">{{ $item[0] }}</span>{{ $item[1] }}</a>
@endforeach
@if(auth()->user()->role==='manager')<a class="navlink {{ request()->is('tasks') && request('scope')==='my'?'current':'' }}" href="/tasks?scope=my">My tasks</a>@endif
@if(auth()->user()->isSupervisor())<a class="navlink {{ request()->is('recurring-tasks')?'current':'' }}" href="/recurring-tasks">Recurring tasks</a><a class="navlink {{ request()->is('reviews')?'current':'' }}" href="/reviews">Review tasks</a>@endif
@if(auth()->user()->role==='admin')<a class="navlink {{ request()->is('admin/company-settings')?'current':'' }}" href="/admin/company-settings">Company settings</a><a class="navlink {{ request()->is('admin/company-policy*')?'current':'' }}" href="/admin/company-policy">Company policy</a><a class="navlink {{ request()->is('admin/users')?'current':'' }}" href="/admin/users">People</a><a class="navlink {{ request()->is('admin/settings')?'current':'' }}" href="/admin/settings">Store settings</a>
@endif<a class="navlink" href="/company-policy">Read company policy</a>@endif</nav></div><div class="sidebar-foot"><p class="eyebrow" style="color:#b0c99c">A little clarity. A better shift.</p><p style="font-size:12px;color:#9eb7a7;line-height:1.8">Every task has an owner.<br>Every effort leaves a record.</p></div></aside>
<div class="main"><header class="topbar"><span class="muted">{{ $workspace ? $workspace->company->name.' · '.$workspace->name : 'Platform workspace' }} <span style="margin:0 12px;color:#c4cec1">/</span> <strong style="color:#284c3b">
@yield('title','Overview')</strong></span><div class="small-links">@if($workspace)<a class="btn" href="/stores">Switch store</a>@endif @if(auth()->user()->role!=='super_admin')<a href="/notifications" aria-label="Notifications">Notifications
@php($unread=\App\Models\Alert::visibleTo(auth()->user())->whereNull('read_at')->count())
@if($unread)<span class="badge">{{ $unread }}</span>
@endif</a>@endif<span class="avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><span class="desktop-only"><strong>{{ auth()->user()->name }}</strong><br><span class="subline">{{ ['super_admin'=>'Super Admin','admin'=>'Store Owner','manager'=>'Manager','employee'=>'Employee'][auth()->user()->role] ?? auth()->user()->role }}</span></span><form method="post" action="/logout">
@csrf @include('partials.store-context')<button class="muted" style="font-size:12px">Sign out</button></form></div></header><main class="content">
@include('partials.messages')
@yield('content')</main></div></div>
@stack('scripts')</body></html>
