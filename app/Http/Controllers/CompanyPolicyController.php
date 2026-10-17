<?php

namespace App\Http\Controllers;

use App\Services\CompanyPolicyService;
use Illuminate\Http\Request;

class CompanyPolicyController extends Controller
{
    public function show(Request $request, CompanyPolicyService $service)
    {
        $data = $request->validate(['policy_id' => 'nullable|integer|min:1']);
        $state = $service->state($request->user(), isset($data['policy_id']) ? (int) $data['policy_id'] : null);
        if ($request->is('api/*')) {
            return response()->json($state)->header('Cache-Control', 'private, no-store');
        }

        return response()->view('policies.acknowledge', ['state' => $state, 'policy' => $state['policy'], 'required' => $state['selected_required']])->header('Cache-Control', 'private, no-store');
    }

    public function acknowledge(Request $request, CompanyPolicyService $service)
    {
        $data = $request->validate(['policy_version_id' => 'required|integer|min:1', 'acknowledged' => 'required|accepted', 'company_id' => 'prohibited', 'user_id' => 'prohibited', 'acknowledged_at' => 'prohibited']);
        $result = $service->acknowledge($request->user(), (int) $data['policy_version_id']);
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json($result, $result['acknowledged'] ? 200 : 423)->header('Cache-Control', 'private, no-store');
        }
        if (! $result['acknowledged']) {
            return redirect()->route('company-policy.show', ['policy_id' => $result['policy']['policy_id']])->with('policy_changed', $result['message']);
        }
        if ($service->requiredFor($request->user())) {
            return redirect()->route('company-policy.show')->with('success', 'Policy acknowledged. Confirm the remaining policies to continue.');
        }
        $intended = $request->session()->pull('policy.intended', '/');
        $safe = is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//') && ! str_contains($intended, '\\') && ! preg_match('/[\x00-\x1F]/', $intended);

        return redirect($safe ? $intended : '/')->with('success', 'Company policy acknowledged.');
    }
}
