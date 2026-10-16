<?php

namespace App\Http\Controllers;

use App\Models\CompanyPolicyVersion;
use App\Models\User;
use App\Services\CompanyPolicyService;
use Illuminate\Http\Request;

class CompanyPolicyAdminController extends Controller
{
    private function owner(Request $request): void
    {
        abort_unless($request->user()->role === 'admin', 403);
    }

    public function index(Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $filters = $request->validate(['role' => 'nullable|in:employee,manager', 'ack_status' => 'nullable|in:acknowledged,outstanding']);
        $company = $service->company($request->user());
        $current = $service->current($request->user());
        $staff = $request->user()->membersInStore()->where('active', true)->whereIn('role', User::ASSIGNEE_ROLES)
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $request->user()->storeId())->where('store_user.active', true))
            ->leftJoin('company_policy_acknowledgements as ack', function ($join) use ($company, $current) {
                $join->on('ack.user_id', '=', 'users.id')->where('ack.company_id', $company->id)->where('ack.policy_version_id', $current?->id ?? 0);
            })->select(['users.id', 'users.name', 'users.role', 'ack.acknowledged_at'])
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($current && ($filters['ack_status'] ?? null) === 'acknowledged', fn ($query) => $query->whereNotNull('ack.acknowledged_at'))
            ->when($current && ($filters['ack_status'] ?? null) === 'outstanding', fn ($query) => $query->whereNull('ack.acknowledged_at'))
            ->orderBy('users.name')->paginate(25)->withQueryString();
        $versions = $company->policyVersions()->with('publisher')->paginate(10, ['*'], 'versions_page');

        return response()->view('admin.company-policy', compact('company', 'current', 'staff', 'versions'))->header('Cache-Control', 'private, no-store');
    }

    public function draft(Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $data = $request->validate(['title' => 'required|string|max:180', 'content' => 'required|string|max:100000', 'draft_revision' => 'required|integer|min:0', 'company_id' => 'prohibited']);
        $service->saveDraft($request->user(), $data['title'], $data['content'], (int) $data['draft_revision']);

        return redirect('/admin/company-policy')->with('success', 'Draft saved. Staff access and notifications are unchanged.');
    }

    public function publish(Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $data = $request->validate(['draft_revision' => 'required|integer|min:0', 'company_id' => 'prohibited']);
        $version = $service->publish($request->user(), (int) $data['draft_revision']);

        return redirect('/admin/company-policy')->with('success', 'Company policy version '.$version->version.' published. Active managers and employees have been notified.');
    }

    public function version(int $version, Request $request, CompanyPolicyService $service)
    {
        $this->owner($request);
        $policy = CompanyPolicyVersion::where('company_id', $service->company($request->user())->id)->findOrFail($version);

        return response()->view('policies.version', compact('policy'))->header('Cache-Control', 'private, no-store');
    }
}
