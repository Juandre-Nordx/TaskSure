<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CompanyPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request)
    {
        $v = $request->validate(['email' => 'required|email|max:180', 'password' => 'required|string|max:1024', 'device_name' => 'required|string|max:80']);
        $user = User::where('email', $v['email'])->first();
        if (! $user || ! Hash::check($v['password'], $user->password) || ! $user->active) {
            throw ValidationException::withMessages(['email' => 'These credentials are invalid or the account is inactive.']);
        }
        abort_unless(in_array($user->role, User::ASSIGNEE_ROLES), 403, 'Managers and employees can use this app. Store owners use the web dashboard.');
        $profile = $this->profile($user);
        $token = $user->createToken('mobile:'.$v['device_name'], ['mobile', $user->role], now()->addDays(30));

        return response()->json($profile + ['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at->toIso8601String()])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request)
    {
        return response()->json($this->profile($request->user()));
    }

    private function profile(User $user): array
    {
        $policy = app(CompanyPolicyService::class)->requiredFor($user);

        return ['user' => $user->only(['id', 'name', 'email', 'role']), 'policy_required' => $policy !== null, 'policy' => $policy?->toPolicyData(), 'config' => ['timezone' => config('tasksure.timezone'), 'upload_max_kb' => config('tasksure.upload_max_kb'), 'push_enabled' => (bool) config('mobile.push_enabled')]];
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }
}
