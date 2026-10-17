<?php

namespace App\Http\Controllers;

use App\Models\CompanyPolicyAcknowledgement;
use App\Models\CompanyPolicyVersion;
use App\Models\User;
use App\Rules\PolicyPdf;
use App\Services\CompanyPolicyService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompanyPolicyAdminController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }

    public function index(Request $request, CompanyPolicyService $service)
    {
        return $this->page($request, $service);
    }

    public function create(Request $request, CompanyPolicyService $service)
    {
        return $this->page($request, $service, true);
    }

    private function page(Request $request, CompanyPolicyService $service, bool $adding = false)
    {
        $this->owner($request);
        $filters = $request->validate(['role' => 'nullable|in:employee,manager', 'ack_status' => 'nullable|in:acknowledged,outstanding', 'policy_id' => 'nullable|integer|min:1']);
        $company = $service->company($request->user());
        $policies = $company->policies()->with('currentVersion')->withCount('versions')->orderBy('id')->get();
        $entry = $adding ? null : (isset($filters['policy_id']) ? $policies->firstWhere('id', (int) $filters['policy_id']) : $policies->first());
        abort_if(! $adding && isset($filters['policy_id']) && ! $entry, 404);
        $adding = $entry === null;
        $current = $entry?->currentVersion;
        $staff = $request->user()->membersInStore()->where('active', true)->whereIn('role', User::ASSIGNEE_ROLES)
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $request->user()->storeId())->where('store_user.active', true))
            ->leftJoin('company_policy_acknowledgements as ack', function ($join) use ($company, $current) {
                $join->on('ack.user_id', '=', 'users.id')->where('ack.company_id', $company->id)->where('ack.policy_version_id', $current?->id ?? 0);
            })->select(['users.id', 'users.name', 'users.role', 'ack.acknowledged_at'])
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($current && ($filters['ack_status'] ?? null) === 'acknowledged', fn ($query) => $query->whereNotNull('ack.acknowledged_at'))
            ->when($current && ($filters['ack_status'] ?? null) === 'outstanding', fn ($query) => $query->whereNull('ack.acknowledged_at'))
            ->orderBy('users.name')->paginate(25)->withQueryString();
        $versions = $company->policyVersions()->where('company_policy_id', $entry?->id ?? 0)->with('publisher')->paginate(10, ['*'], 'versions_page');

        return response()->view('admin.company-policy', compact('company', 'policies', 'entry', 'adding', 'current', 'staff', 'versions'))->header('Cache-Control', 'private, no-store');
    }

    public function draft(Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $data = $this->validateDraft($request);
        $service->saveDraft($request->user(), $data['title'], $data['content'] ?? '', (int) $data['draft_revision'], $request->file('policy_pdf'), $data['format'], isset($data['policy_id']) ? (int) $data['policy_id'] : null);

        return redirect('/admin/company-policy'.(isset($data['policy_id']) ? '?policy_id='.$data['policy_id'] : ''))->with('success', 'Draft saved. Staff access and notifications are unchanged.');
    }

    public function store(Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $data = $this->validateDraft($request, true);
        $entry = $service->createDraft($request->user(), $data['title'], $data['content'] ?? '', $request->file('policy_pdf'), $data['format']);

        return redirect('/admin/company-policy?policy_id='.$entry->id)->with('success', 'New policy draft saved. Publish it when ready to notify staff.');
    }

    private function validateDraft(Request $request, bool $creating = false): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:180', 'content' => 'nullable|string|max:100000',
            'format' => 'nullable|in:pdf,text',
            'policy_pdf' => ['nullable', 'file', 'mimes:pdf', 'extensions:pdf', 'max:'.config('tasksure.upload_max_kb'), new PolicyPdf],
            'draft_revision' => 'required|integer|min:0', 'company_id' => 'prohibited',
            'policy_id' => $creating ? 'prohibited' : 'nullable|integer|min:1',
        ]);
        $format = $data['format'] ?? ($request->hasFile('policy_pdf') ? 'pdf' : 'text');
        if ($format === 'text' && $request->hasFile('policy_pdf')) {
            throw ValidationException::withMessages(['policy_pdf' => 'Choose PDF upload to use the selected file.']);
        }

        $data['format'] = $format;

        return $data;
    }

    public function publish(Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $data = $request->validate(['draft_revision' => 'required|integer|min:0', 'company_id' => 'prohibited', 'policy_id' => 'nullable|integer|min:1']);
        $version = $service->publish($request->user(), (int) $data['draft_revision'], isset($data['policy_id']) ? (int) $data['policy_id'] : null);

        return redirect('/admin/company-policy'.(isset($data['policy_id']) ? '?policy_id='.$data['policy_id'] : ''))->with('success', 'Company policy version '.$version->version.' published. Active managers and employees have been notified.');
    }

    public function version(int $version, Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $policy = CompanyPolicyVersion::where('company_id', $service->company($request->user())->id)->findOrFail($version);
        $acknowledgements = CompanyPolicyAcknowledgement::with('user')->where('company_id', $policy->company_id)
            ->where('policy_version_id', $policy->id)->orderByDesc('acknowledged_at')->paginate(25);

        return response()->view('policies.version', compact('policy', 'acknowledgements'))->header('Cache-Control', 'private, no-store');
    }
}
