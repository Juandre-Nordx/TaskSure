@extends('layout')
@section('title','Published company policy')
@section('content')
<div class="pagehead"><div><p class="eyebrow">IMMUTABLE PUBLISHED VERSION {{ $policy->version }}</p><h1>{{ $policy->title }}</h1><p class="field-help">Published {{ \App\Support\BusinessTime::show($policy->published_at) }} · SAST · {{ $policy->publisher->name }}</p></div><a class="btn" href="/admin/company-policy?policy_id={{ $policy->company_policy_id }}">Company policies</a></div>
<section class="card">
@if($policy->pdf_path)
@include('policies.pdf', ['pdfName'=>$policy->pdf_name, 'pdfSize'=>$policy->pdf_size, 'pdfTitle'=>$policy->title, 'pdfUrl'=>'/company-policy/versions/'.$policy->id.'/pdf'])
@else<div class="note">{{ $policy->content }}</div>@endif
</section>
<section class="card" style="margin-top:24px"><h2>Acknowledgements for version {{ $policy->version }}</h2><p class="field-help">Preserved confirmations for this exact version, including staff who are now inactive.</p>
<div class="table-wrap"><table><thead><tr><th>Staff name</th><th>Role</th><th>Version</th><th>Status</th><th>Acknowledged · SAST</th></tr></thead><tbody>
@forelse($acknowledgements as $ack)<tr><td>{{ $ack->user->name }}</td><td>{{ ucfirst($ack->user->role) }}</td><td>{{ $policy->version }}</td><td>Acknowledged</td><td>{{ \App\Support\BusinessTime::show($ack->acknowledged_at) }}</td></tr>
@empty<tr><td colspan="5">No acknowledgements for this version.</td></tr>@endforelse
</tbody></table></div>{{ $acknowledgements->links() }}</section>
@endsection
