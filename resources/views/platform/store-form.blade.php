@extends('layout')
@section('title',$store?'Edit store':'Create store')
@section('content')
@include('platform.tabs')
<div class="pagehead"><h1>{{ $store?'Edit store':'Create store' }}</h1><a class="btn" href="/super-admin">Back to stores</a></div>
<section class="card">
    <form method="post" action="{{ $store?'/super-admin/stores/'.$store->id:'/super-admin/stores' }}" class="stack" data-owner-setup>
        @csrf @include('partials.store-context')
        <div><label for="name">Store name</label><input id="name" name="name" value="{{ old('name',$store?->name) }}" required maxlength="120"></div>
        @if($store)
            <p>Company: <strong>{{ $store->company->name }}</strong></p>
            <p class="field-help">Existing stores keep their company and policy history. The owner can edit company names in Company settings.</p>
        @else
            <div><label for="company-id">Existing company (optional)</label><select name="company_id" id="company-id"><option value="">Create a new company</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id')==$company->id)>{{ $company->name }}</option>@endforeach</select><p class="field-help">Stores in the same company share company policy versions and acknowledgements.</p></div>
            <div><label for="company-name">New company name</label><input id="company-name" name="company_name" value="{{ old('company_name') }}" maxlength="120"><p class="field-help">Required when creating a new company.</p></div>
        @endif
        <div><label for="owner-id">Assigned owner</label><select name="owner_id" id="owner-id"><option value="">Create a new owner</option>@foreach($owners as $owner)<option value="{{ $owner->id }}" @selected(old('owner_id',$store?->owner_id)==$owner->id)>{{ $owner->name }} · {{ $owner->email }}</option>@endforeach</select></div>
        <fieldset class="stack" data-new-owner-fields>
            <legend>New owner account</legend>
            <div class="form-grid">
                <div><label for="owner-name">New owner name</label><input id="owner-name" name="owner_name" value="{{ old('owner_name') }}" maxlength="100" data-owner-required></div>
                <div><label for="owner-email">New owner email</label><input id="owner-email" name="owner_email" type="email" value="{{ old('owner_email') }}" maxlength="180" data-owner-required></div>
            </div>
            @include('platform.owner-password-fields', ['prefix'=>'owner-initial','required'=>false,'label'=>'Initial password (optional)'])
            <p class="field-help">Set an initial password so the new owner can sign in immediately. Leave both password fields blank to send an emailed setup link; outgoing email must be configured. Passwords are never displayed or emailed.</p>
        </fieldset>
        <p class="field-help">Assigning an existing owner keeps their password. Existing eligible owners may own multiple stores.</p>
        <div><label for="active">Store status</label><select id="active" name="active"><option value="1" @selected(old('active',$store?->active ?? true))>Active</option><option value="0" @selected(!old('active',$store?->active ?? true))>Inactive</option></select><p class="field-help">Deactivation blocks store operations while retaining all data. You can reactivate it here.</p></div>
        <button class="btn primary">Save store</button>
    </form>
</section>
@if($store?->owner?->role==='admin' && $store->owner->active)
    <section class="card" style="margin-top:24px">
        <h2>Set owner password</h2>
        <p style="margin-top:12px"><strong>{{ $store->owner->name }}</strong> · {{ $store->owner->email }}</p>
        <p class="field-help">This changes the saved owner's password across all their stores and signs them out. Save any change of assigned owner above before using this form.</p>
        <form method="post" action="/super-admin/stores/{{ $store->id }}/owner-password" class="stack" style="margin-top:20px" data-owner-password-reset>
            @csrf
            <input type="hidden" name="owner_id" value="{{ $store->owner_id }}">
            @include('platform.owner-password-fields', ['prefix'=>'owner-reset','required'=>true,'label'=>'New owner password'])
            <button class="btn primary">Set owner password</button>
        </form>
        <form method="post" action="/super-admin/stores/{{ $store->id }}/invite" style="margin-top:20px">@csrf<button class="btn">Send setup link</button><p class="field-help">Alternatively, email the owner a link to choose their own password. Outgoing email must be configured.</p></form>
    </section>
@endif
@endsection
