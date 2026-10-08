
@extends('layout')
@section('title','People')
@section('content')<div class="pagehead"><div><p class="eyebrow">YOUR STORE TEAM</p><h1>People & permissions</h1><p class="muted" style="margin-top:9px">Manage accounts and choose which employees each manager can oversee.</p></div></div><div class="grid-two"><section class="card"><h2>Team accounts</h2>
@foreach($users as $u)<details style="border-bottom:1px solid var(--line)"><summary>{{ $u->name }} <span class="badge">{{ $u->role }}</span> 
@if(!$u->active)<span class="badge cancelled">Inactive</span>
@endif</summary><form method="post" action="/admin/users/{{ $u->id }}" style="padding:15px 0">
@csrf<input type="hidden" name="role" value="{{ $u->role }}"><div class="form-grid"><div><label>Name</label><input name="name" value="{{ $u->name }}" required></div><div><label>Email</label><input type="email" name="email" value="{{ $u->email }}" required></div><div><label>Account status</label><select name="active"><option value="1" 
@selected($u->active)>Active</option><option value="0" 
@selected(!$u->active)>Inactive</option></select></div><div><label>New password (optional)</label><input type="password" name="password" autocomplete="new-password"></div><div><label>Confirm new password</label><input type="password" name="password_confirmation" autocomplete="new-password"></div>
@if($u->role==='manager')<fieldset class="wide"><legend>Permitted employees</legend>
@foreach($employees as $e)<label class="check"><input type="checkbox" name="employees[]" value="{{ $e->id }}" 
@checked($u->employees->contains('id',$e->id))>{{ $e->name }}</label>
@endforeach</fieldset>
@endif</div><p class="field-help">Deactivation blocks sign-in and keeps historical records. Roles stay fixed to preserve task history. Passwords require 12 characters, mixed case, and a number.</p><button class="btn primary" style="margin-top:15px">Save account</button></form></details>
@endforeach</section><section class="card"><h2>Add a team member</h2><form method="post" action="/admin/users" class="stack" style="margin-top:20px">
@csrf<div><label>Name</label><input name="name" required></div><div><label>Email</label><input type="email" name="email" required></div><div><label>Role</label><select name="role"><option value="employee">Employee</option><option value="manager">Manager</option><option value="admin">Owner / admin</option></select></div><input type="hidden" name="active" value="1"><div><label>Initial password</label><input type="password" name="password" required autocomplete="new-password"><p class="field-help">12+ characters, mixed case, and a number. Share securely.</p></div><div><label>Confirm password</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div><fieldset><legend>Scope (managers only)</legend>
@foreach($employees as $e)<label class="check"><input type="checkbox" name="employees[]" value="{{ $e->id }}">{{ $e->name }}</label>
@endforeach</fieldset><button class="btn primary">Create account</button></form></section></div><section class="card" style="margin-top:24px"><h2>Recent account & settings audit</h2><div class="table-wrap"><table><thead><tr><th>When · SAST</th><th>Actor ID</th><th>Subject ID</th><th>Action</th><th>Changes</th></tr></thead><tbody>
@foreach($audits as $a)<tr><td>{{ \App\Support\BusinessTime::show($a->created_at) }}</td><td>{{ $a->actor_id??'Bootstrap' }}</td><td>{{ $a->subject_id??'—' }}</td><td>{{ $a->action }}</td><td><details><summary>View changes</summary><pre style="white-space:pre-wrap;font-size:11px">{{ json_encode($a->data,JSON_PRETTY_PRINT) }}</pre></details></td></tr>
@endforeach</tbody></table></div></section>
@endsection
