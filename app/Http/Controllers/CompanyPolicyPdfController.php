<?php

namespace App\Http\Controllers;

use App\Models\CompanyPolicyVersion;
use App\Services\CompanyPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyPolicyPdfController extends Controller
{
    public function published(int $version, Request $request, CompanyPolicyService $service)
    {
        $company = $service->company($request->user());
        $policy = CompanyPolicyVersion::where('company_id', $company->id)->findOrFail($version);

        return $this->document($policy->pdf_path, 'company-policy-'.$policy->company_policy_id.'-v'.$policy->version.'.pdf', $request);
    }

    public function draft(Request $request, CompanyPolicyService $service)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $company = $service->company($request->user());
        $data = $request->validate(['policy_id' => 'nullable|integer|min:1']);
        $entry = isset($data['policy_id']) ? $company->policies()->findOrFail($data['policy_id']) : $service->defaultPolicy($request->user());

        return $this->document($entry?->draft_pdf_path, 'company-policy-draft.pdf', $request);
    }

    private function document(?string $path, string $name, Request $request)
    {
        abort_unless($path && Storage::disk('evidence')->exists($path), 404, 'This policy PDF is unavailable. Contact your store owner.');

        return Storage::disk('evidence')->response($path, $name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "sandbox; frame-ancestors 'self'",
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }
}
